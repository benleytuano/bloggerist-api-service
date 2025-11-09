<?php
namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{

    public function show(Request $request, string $slug)
    {

        try {
            $user = User::where('username', $slug)->firstOrFail();

            if ($user->image) {
                $user->profile_image_url = url($user->image); // Dynamically generate the full URL
            }

            return response()->json($user);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'User not found',
            ], 404);
        } catch (\Throwable $th) {
            return response()->json($th);
        }

    }

    public function followUser(Request $request, $username)
    {
        $userToFollow = User::where('username', $username)->firstOrFail();
        $request->user()->followings()->syncWithoutDetaching($userToFollow->id);

        return response()->json(['message' => 'Successfully followed user']);
    }

    public function unfollowUser(Request $request, $username)
    {
        $userToUnfollow = User::where('username', $username)->firstOrFail();
        $request->user()->followings()->detach($userToUnfollow->id);

        return response()->json(['message' => 'User unfollowed successfully!']);
    }

    public function checkIsFollowed(Request $request, $viewUserId)
    {

        $currentUserId = auth()->id();

        $isExist = DB::table('user_follower')
            ->where('follower_id', $currentUserId)
            ->where('followed_id', $viewUserId)
            ->first();

        if ($isExist) {
            return response()->json([
                "isFollowed" => true,
            ]);
        } else {
            return response()->json([
                "isFollowed" => false,
            ]);
        }

    }

}
