<?php
namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Articles;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ArticlesController extends Controller
{
    public function index(Request $request)
    {

        $limit  = $request->query('limit'); // Retrieves the 'author' query parameter
        $author = $request->query('author');

        $userId = auth()->id(); // Get the authenticated user's ID

        $articles = Articles::with(['user', 'favoritedByUsers' => function ($query) use ($userId) {
            $query->where('user_id', $userId);
        }])
            ->withCount('favoritedByUsers')
            ->when($author, function ($q) use ($author) {
                return $q->where('user_id', $author);
            })
            ->paginate($limit);

        //  Add a custom attribute to indicate if the authenticated user has favorited the article
        $articles->getCollection()->transform(function ($article) use ($userId) {
            $article->is_favorited_by_auth_user = $article->favoritedByUsers->isNotEmpty();

            // Assuming the image path is stored in `$article->profile_image`
            if ($article->user->image) {
                $article->user->profile_image_url = url($article->user->image); // Dynamically generate the full URL
            }

            return $article;
        });

        // Use the parameters as needed
        return response()->json($articles);
    }

    public function feed(Request $request)
    {

        //Authentication required,
        //will return multiple articles created by followed users, ordered by most recent first.

        $limit = $request->query('limit'); // Retrieves the 'author' query parameter

        $userId = auth()->id();

        // Get the IDs of followed users
        $followedUserIds = User::find($userId)
            ->followings()
            ->pluck('followed_id');

        // Get articles from those users, ordered by newest
        $articles = Articles::with(['user'])
            ->whereIn('user_id', $followedUserIds)
            ->orderBy('created_at', 'desc')
            ->paginate($limit)
            ->through(function ($article) {
                if ($article->user->image) {
                    $article->user->profile_image_url = url($article->user->image); // Dynamically generate the full URL
                }

                return $article;
            });

        return response()->json($articles);

        return response()->json($articles);
    }

    public function favoriteArticleFeed(Request $request)
    {
        $limit = $request->query('limit'); // Retrieves the 'author' query parameter

        $userId = auth()->id();

        $articles = Articles::whereHas('favoritedByUsers', function ($query) use ($userId) {
            $query->where('user_id', $userId);
        })
            ->with(['user'])                // Eager load the user who created the article
            ->withCount('favoritedByUsers') // Count the number of users who favorited the article
            ->orderBy('created_at', 'desc')
            ->paginate($limit);

        //  Add a custom attribute to indicate if the authenticated user has favorited the article
        $articles->getCollection()->transform(function ($article) use ($userId) {
            $article->is_favorited_by_auth_user = $article->favoritedByUsers->isNotEmpty();

            // Assuming the image path is stored in `$article->profile_image`
            if ($article->user->image) {
                $article->user->profile_image_url = url($article->user->image); // Dynamically generate the full URL
            }

            return $article;
        });

        return response()->json($articles);
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
        if (isset($validatedData['title'])) {
            $validatedData['slug'] = $this->generateUniqueSlug($validatedData['title']);
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

    public function generateUniqueSlug($title)
    {
        // Generate the initial slug
        $slug = Str::slug($title);

                                      // Append a random unique identifier
        $randomId   = Str::random(8); // Generates an 8-character random string
        $uniqueSlug = "{$slug}-{$randomId}";

        // Ensure the slug is unique in the database
        while (Articles::where('slug', $uniqueSlug)->exists()) {
            $randomId   = Str::random(8); // Regenerate if duplicate
            $uniqueSlug = "{$slug}-{$randomId}";
        }

        return $uniqueSlug;
    }

}
