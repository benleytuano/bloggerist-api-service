<?php
namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AuthController extends Controller
{

    public function register(Request $request)
    {
        $fields = $request->validate([
            'username' => 'required|string|max:50|unique:users',
            'email'    => 'required|email|unique:users',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create($fields);

        return [
            'user' => $user,
        ];
    }

    /**
     * Get a JWT via given credentials.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {

        $credentials = $request->validate([
            'email'    => 'required|email|exists:users',
            'password' => 'required|min:8',
        ]);

        if (! $token = auth()->attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $user = auth()->user();

        $userJsonData = [
            'email'    => $user->email,
            'token'    => $token,
            'username' => $user->username,
            'bio'      => $user->bio,
            'image'    => null,

        ];

        return response()->json([
            'user' => $userJsonData,
        ]);
    }

    public function getCurrentUser()
    {

        $user = auth()->user();

        if ($user->image) {
            $user->profile_image_url = url($user->image); // Dynamically generate the full URL
        }

        return response()->json($user);

        // $userJsonData = [
        //     'email' => $user->email,
        //     'username' => $user->username,
        //     'bio' => $user->bio,
        //     'image' => null

        // ];

    }

    /**
     * Log the user out (Invalidate the token).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        auth()->logout();

        return response()->json(['message' => 'Successfully logged out']);
    }

    public function update(Request $request)
    {
        // Get the authenticated user
        $user = auth()->user();

        // Check if the request contains any data
        if (! $request->hasAny(['email', 'username', 'password', 'bio', 'image'])) {
            return response()->json(['error' => 'No data provided'], 400);
        }

        // Validate fields that are provided (skip empty fields)
        $fields = $request->validate([
            'email'    => 'nullable|email|unique:users,email,' . $user->id,
            'username' => 'nullable|string|unique:users,username,' . $user->id,
            'password' => 'nullable|string|min:8',
            'bio'      => 'nullable|string',
            'image'    => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        // Update profile image if provided
        if ($request->hasFile('image')) {
            // Store the image in public storage
            $path            = $request->file('image')->store('images', 'public');
            $fields['image'] = Storage::url($path); // Store the public URL
        }

                                              // Update the authenticated user with the provided fields
        $user->update(array_filter($fields)); // Use array_filter to ignore null values

        // Return the updated user data in the specified JSON format
        return response()->json([
            'user' => [
                'email'    => $user->email,
                'username' => $user->username,
                'bio'      => $user->bio,
                'image'    => $user->image,
            ],
        ]);
    }

    /**
     * Get the token array structure.
     *
     * @param  string $token
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithToken($token)
    {
        return response()->json([
            'access_token' => $token,
            'token_type'   => 'bearer',
            'expires_in'   => auth()->factory()->getTTL() * 60,
        ]);
    }

    public function getFollowers(Request $request)
    {

        $user = User::find(1);

        // $userToFollow = User::where('username', $username)->firstOrFail();
        // $request->user()->followings()->syncWithoutDetaching($userToFollow->id);

        return $user->followers;

    }

    public function getFollowings()
    {

        $user = User::find(1);

        // $userToUnfollow = User::where('username', $username)->firstOrFail();
        // $request->user()->followings()->detach($userToUnfollow->id);

        // return response()->json(['message' => 'User unfollowed successfully!']);

        return $user->followings;
    }

}
