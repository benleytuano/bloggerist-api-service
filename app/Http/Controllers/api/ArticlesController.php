<?php
namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Articles;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ArticlesController extends Controller
{
    public function index(Request $request): \Illuminate\Http\JsonResponse
    {
        // ===== STEP 1: Validate & Sanitize Query Parameters =====
        $validator = Validator::make($request->query(), [
            'limit'  => 'nullable|integer|min:1|max:100',
            'author' => 'nullable|integer|exists:users,id',
            'cursor' => 'nullable|string', // Encoded cursor from previous response
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        // ===== STEP 2: Set Defaults & Enforce Max Limit =====
        $perPage = min($validated['limit'] ?? 10, 100); // Default: 10, Max: 100
        $authorFilter = $validated['author'] ?? null;
        $cursor = $validated['cursor'] ?? null;

        // IMPORTANT: When filters change (e.g., different author), the frontend MUST
        // reset the cursor to null to avoid incorrect pagination boundaries.
        // Store filter state in frontend and compare before using cached cursor.

        // ===== STEP 3: Build Query with Deterministic Ordering =====
        $userId = auth()->id(); // Current authenticated user (null if guest)

        $query = Articles::query()
            // Eager load user relationship
            ->with(['user'])
            // Eager load favoritedByUsers ONLY for current auth user (reduces payload)
            ->with(['favoritedByUsers' => function ($query) use ($userId) {
                if ($userId) {
                    $query->where('user_id', $userId);
                }
            }])
            // Count total favorites for each article
            ->withCount('favoritedByUsers')
            // Filter by author if provided
            ->when($authorFilter, function ($q) use ($authorFilter) {
                return $q->where('user_id', $authorFilter);
            })
            // CRITICAL: Deterministic ordering prevents duplicates/skipped items
            // Primary: created_at DESC (most recent first)
            // Tie-breaker: id DESC (ensures uniqueness when created_at is identical)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        // ===== STEP 4: Execute Cursor Pagination =====
        // cursorPaginate uses the ordering columns to create an encoded cursor
        $paginator = $query->cursorPaginate($perPage);

        // ===== STEP 5: Transform Each Article =====
        $transformedArticles = $paginator->getCollection()->map(function ($article) use ($userId) {
            // Check if auth user has favorited this article
            $article->is_favorited_by_auth_user = $userId 
                ? $article->favoritedByUsers->isNotEmpty() 
                : false;

            // Generate full URL for user profile image
            if ($article->user && $article->user->image) {
                $article->user->profile_image_url = url($article->user->image);
            }

            // CLEANUP: Remove the favoritedByUsers relation to reduce JSON payload
            // (we already computed is_favorited_by_auth_user above)
            unset($article->favoritedByUsers);

            return $article;
        });

        // ===== STEP 6: Build Response with Cursor Metadata =====
        // Extract cursor strings (Laravel encodes them automatically)
        $nextCursor = $paginator->nextCursor()?->encode(); // null if no more pages
        $prevCursor = $paginator->previousCursor()?->encode(); // null if on first page

        return response()->json([
            'data' => $transformedArticles->toArray(), // Plain array, not Eloquent models
            'meta' => [
                'per_page'    => $paginator->perPage(),
                'has_more'    => $paginator->hasMorePages(), // Boolean: are there more results?
                'next_cursor' => $nextCursor, // Encoded string or null
                'prev_cursor' => $prevCursor, // Encoded string or null
            ],
        ]);
    }

    public function feed(Request $request): \Illuminate\Http\JsonResponse
    {
        // ===== STEP 1: Validate & Sanitize Query Parameters =====
        $validator = Validator::make($request->query(), [
            'limit'  => 'nullable|integer|min:1|max:100',
            'cursor' => 'nullable|string', // Encoded cursor from previous response
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        // ===== STEP 2: Set Defaults & Enforce Max Limit =====
        $perPage = min($validated['limit'] ?? 10, 100); // Default: 10, Max: 100
        $cursor = $validated['cursor'] ?? null;

        // ===== STEP 3: Get Current User & Followed User IDs =====
        $userId = auth()->id(); // Current authenticated user (required for feed)

        // Get the IDs of followed users
        $followedUserIds = User::find($userId)
            ->followings()
            ->pluck('followed_id');

        // ===== STEP 4: Build Query with Deterministic Ordering =====
        $query = Articles::query()
            // Eager load user relationship
            ->with(['user'])
            // Eager load favoritedByUsers ONLY for current auth user (reduces payload)
            ->with(['favoritedByUsers' => function ($query) use ($userId) {
                $query->where('user_id', $userId);
            }])
            // Count total favorites for each article
            ->withCount('favoritedByUsers')
            // Filter by followed users only
            ->whereIn('user_id', $followedUserIds)
            // CRITICAL: Deterministic ordering prevents duplicates/skipped items
            // Primary: created_at DESC (most recent first)
            // Tie-breaker: id DESC (ensures uniqueness when created_at is identical)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        // ===== STEP 5: Execute Cursor Pagination =====
        // cursorPaginate uses the ordering columns to create an encoded cursor
        $paginator = $query->cursorPaginate($perPage);

        // ===== STEP 6: Transform Each Article =====
        $transformedArticles = $paginator->getCollection()->map(function ($article) use ($userId) {
            // Check if auth user has favorited this article
            $article->is_favorited_by_auth_user = $article->favoritedByUsers->isNotEmpty();

            // Generate full URL for user profile image
            if ($article->user && $article->user->image) {
                $article->user->profile_image_url = url($article->user->image);
            }

            // CLEANUP: Remove the favoritedByUsers relation to reduce JSON payload
            // (we already computed is_favorited_by_auth_user above)
            unset($article->favoritedByUsers);

            return $article;
        });

        // ===== STEP 7: Build Response with Cursor Metadata =====
        // Extract cursor strings (Laravel encodes them automatically)
        $nextCursor = $paginator->nextCursor()?->encode(); // null if no more pages
        $prevCursor = $paginator->previousCursor()?->encode(); // null if on first page

        return response()->json([
            'data' => $transformedArticles->toArray(), // Plain array, not Eloquent models
            'meta' => [
                'per_page'    => $paginator->perPage(),
                'has_more'    => $paginator->hasMorePages(), // Boolean: are there more results?
                'next_cursor' => $nextCursor, // Encoded string or null
                'prev_cursor' => $prevCursor, // Encoded string or null
            ],
        ]);
    }

    public function favoriteArticleFeed(Request $request): \Illuminate\Http\JsonResponse
    {
        // ===== STEP 1: Validate & Sanitize Query Parameters =====
        $validator = Validator::make($request->query(), [
            'limit'  => 'nullable|integer|min:1|max:100',
            'cursor' => 'nullable|string', // Encoded cursor from previous response
        ]);

        if ($validator->fails()) {
            return response()->json([
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        // ===== STEP 2: Set Defaults & Enforce Max Limit =====
        $perPage = min($validated['limit'] ?? 10, 100); // Default: 10, Max: 100
        $cursor = $validated['cursor'] ?? null;

        // ===== STEP 3: Get Current User ID =====
        $userId = auth()->id(); // Current authenticated user (required for favorites)

        // ===== STEP 4: Build Query with Deterministic Ordering =====
        $query = Articles::query()
            // Filter to only articles favorited by the current user
            ->whereHas('favoritedByUsers', function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            // Eager load user relationship
            ->with(['user'])
            // Eager load favoritedByUsers ONLY for current auth user (reduces payload)
            ->with(['favoritedByUsers' => function ($query) use ($userId) {
                $query->where('user_id', $userId);
            }])
            // Count total favorites for each article
            ->withCount('favoritedByUsers')
            // CRITICAL: Deterministic ordering prevents duplicates/skipped items
            // Primary: created_at DESC (most recent first)
            // Tie-breaker: id DESC (ensures uniqueness when created_at is identical)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        // ===== STEP 5: Execute Cursor Pagination =====
        // cursorPaginate uses the ordering columns to create an encoded cursor
        $paginator = $query->cursorPaginate($perPage);

        // ===== STEP 6: Transform Each Article =====
        $transformedArticles = $paginator->getCollection()->map(function ($article) use ($userId) {
            // Check if auth user has favorited this article (will always be true for this feed)
            $article->is_favorited_by_auth_user = $article->favoritedByUsers->isNotEmpty();

            // Generate full URL for user profile image
            if ($article->user && $article->user->image) {
                $article->user->profile_image_url = url($article->user->image);
            }

            // CLEANUP: Remove the favoritedByUsers relation to reduce JSON payload
            // (we already computed is_favorited_by_auth_user above)
            unset($article->favoritedByUsers);

            return $article;
        });

        // ===== STEP 7: Build Response with Cursor Metadata =====
        // Extract cursor strings (Laravel encodes them automatically)
        $nextCursor = $paginator->nextCursor()?->encode(); // null if no more pages
        $prevCursor = $paginator->previousCursor()?->encode(); // null if on first page

        return response()->json([
            'data' => $transformedArticles->toArray(), // Plain array, not Eloquent models
            'meta' => [
                'per_page'    => $paginator->perPage(),
                'has_more'    => $paginator->hasMorePages(), // Boolean: are there more results?
                'next_cursor' => $nextCursor, // Encoded string or null
                'prev_cursor' => $prevCursor, // Encoded string or null
            ],
        ]);
    }

    public function show(string $slug)
    {

        // Find the article by slug
        $article = Articles::with('user')->where('slug', $slug)->first();

        if ($article->user->image) {
            $article->user->profile_image_url = url($article->user->image); // Dynamically generate the full URL
        }

        if (! $article) {
            return response()->json(['message' => 'Article not found'], 404);
        }

        return response()->json($article);

    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'required|string|max:55',
            'body'        => 'required|string',
        ]);

        $validatedData['slug']    = $this->generateUniqueSlug($validatedData['title']);
        $validatedData['user_id'] = auth()->id();
        // $validatedData['authUser'] = $request->user();

        $article = Articles::create($validatedData);

        return response()->json($article, 200);

        // return Post::create($validatedData);
    }

    public function update(Request $request, string $slug)
    {
        $article = Articles::where('slug', $slug)->first();

        if (! $article) {
            return response()->json(['message' => 'Article not found'], 404);
        }

        // Remove fields with empty values before validation
        $filteredData = collect($request->only(['title', 'description', 'body']))
            ->filter(fn($value) => ! is_null($value) && $value !== '')
            ->toArray();

        // Check if there is any data left after filtering
        if (empty($filteredData)) {
            return response()->json(['message' => 'No data provided or all fields are empty'], 400);
        }

        // Validate only the filtered data
        $validatedData = validator($filteredData, [
            'title'       => 'sometimes|string|max:255',
            'description' => 'sometimes|string|max:55',
            'body'        => 'sometimes|string',
        ])->validate();

        // Update the slug if the title is present
        // PASS $article->id to exclude it from duplicate check
        if (isset($validatedData['title'])) {
            $validatedData['slug'] = $this->generateUniqueSlug($validatedData['title'], $article->id);
        }

        $article->update($validatedData);

        return response()->json($validatedData);
    }

    public function destroy(string $slug)
    {
        // Find the article by slug
        $article = Articles::where('slug', $slug)->first();

        $userId = auth()->id();

        // Check if the authenticated user is the creator
        if ($article->user_id !== $userId) {
            return response()->json([
                'message' => 'You are not authorized to delete this article.',
            ], 403);
        }

        // Delete the article
        $article->delete();

        return response()->json([
            'message' => 'Article deleted successfully.',
        ], 200);
    }

    public function favoriteArticle($slug)
    {

        $article = Articles::where('slug', $slug)->first();

        $article->favoritedByUsers()->attach(auth()->id());

        return response()->json($article);

    }

    public function unfavoriteArticle($slug)
    {

        $article = Articles::where('slug', $slug)->first();

        $article->favoritedByUsers()->detach(auth()->id());

        return response()->json([
            'message' => "article unfavorite successfully!",
        ], 200);
    }

    public function checkIsFavorite(Request $request, $slug)
    {

        $article = Articles::where('slug', $slug)->first();

        if (! $article) {
            return response()->json([
                'message' => 'Article not found',
            ], 404);
        }

        $currentUserId = auth()->id();

        $isExist = DB::table('article_user')
            ->where('user_id', $currentUserId)
            ->where('article_id', $article->id)
            ->first();

        if ($isExist) {
            return response()->json([
                "isFavorite" => true,
            ]);
        } else {
            return response()->json([
                "isFavorite" => false,
            ]);
        }
    }

    public function generateUniqueSlug($title, $articleId = null)
    {
        // Generate the initial slug
        $slug = Str::slug($title);

        // Append a random unique identifier
        $randomId   = Str::random(8);
        $uniqueSlug = "{$slug}-{$randomId}";

        // Ensure the slug is unique in the database (excluding current article)
        $query = Articles::where('slug', $uniqueSlug);
        
        if ($articleId) {
            $query->where('id', '!=', $articleId);
        }
        
        while ($query->exists()) {
            $randomId   = Str::random(8); // Regenerate if duplicate
            $uniqueSlug = "{$slug}-{$randomId}";
            
            // Reset query for next iteration
            $query = Articles::where('slug', $uniqueSlug);
            if ($articleId) {
                $query->where('id', '!=', $articleId);
            }
        }

        return $uniqueSlug;
    }

}
