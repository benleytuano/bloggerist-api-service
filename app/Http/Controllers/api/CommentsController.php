<?php
namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Articles;
use App\Models\Comments;
use Carbon\Carbon;
use Illuminate\Http\Request;

class CommentsController extends Controller
{
    public function index(Request $request, string $slug)
    {
        $limit = $request->query('limit');

        $article  = Articles::where('slug', $slug)->first();
        $comments = Comments::with(['user'])
                    ->where('article_id', $article->id)
                    ->orderBy('created_at', 'desc')
                    ->paginate($limit)
                    ->through(function ($comment) {
                        $comment->formatted_date = Carbon::parse($comment->created_at)->format('F j, Y');

                        if ($comment->user->image) {
                            $comment->user->profile_image_url = url($comment->user->image); // Dynamically generate the full URL
                        }

                        return $comment;
                    });

        return response()->json($comments, 200);
    }

    public function store(Request $request, string $slug)
    {
        $validatedData = $request->validate([
            'body' => 'required|string',
        ]);

        // Find the article by slug
        $article = Articles::where('slug', $slug)->first();

        $validatedData['article_id'] = $article->id;
        $validatedData['user_id']    = auth()->id();
        // $validatedData['authUser'] = $request->user();

        $comment = Comments::create($validatedData)->load('user');

        return response()->json(["comment" => $comment], 200);
    }

    public function destroy(string $slug, string $id)
    {

        $comment = Comments::where('id', $id)->first();

        // simple authorization
        $userId = auth()->id();

        if ($comment->user_id !== $userId) {
            return response()->json([
                'message' => 'You are not authorized to delete this comment.',
            ], 403);
        }

        $comment->delete();

        return response()->json([
            'message' => 'Comment deleted successfully!',
        ], 200);

    }
}
