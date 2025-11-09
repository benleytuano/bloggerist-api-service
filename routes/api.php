<?php

use App\Http\Controllers\api\ArticlesController;
use App\Http\Controllers\api\AuthController;
use App\Http\Controllers\api\CommentsController;
use App\Http\Controllers\api\UserController;
use Illuminate\Support\Facades\Route;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

//Users
Route::post('/register', [AuthController::class, "register"]);                     // user reegistration
Route::post('/login', [AuthController::class, "login"]);                           // user login
Route::post('/logout', [AuthController::class, "logout"])->middleware('auth:api'); // user logout

Route::get('/user', [AuthController::class, "getCurrentUser"])->middleware('auth:api');
Route::put('/user', [AuthController::class, "update"])->middleware('auth:api');

// Articles
Route::prefix('articles')
    ->group(function () {

        Route::controller(ArticlesController::class)->group(function () {
            Route::get('/', "index");
            Route::get('/feed', 'feed')->middleware('auth:api');
            Route::get('/favoriteFeed', 'favoriteArticleFeed')->middleware('auth:api');
            Route::post('/', 'store')->middleware('auth:api');
            Route::get('/{slug}', 'show');
            Route::put('/{slug}', 'update')->middleware('auth:api');
            Route::delete('/{slug}', 'destroy')->middleware('auth:api');
            Route::get('/{slug}/isFavorite', 'checkIsFavorite')->middleware('auth:api');
            Route::post('/{slug}/favorite', 'favoriteArticle')->middleware('auth:api');
            Route::delete('/{slug}/favorite', 'unfavoriteArticle')->middleware('auth:api');

        });

        Route::controller(CommentsController::class)->group(function () {
            Route::get('/{slug}/comments', "index");
            // Route::get('/{slug}/comments', 'show');
            Route::post('/{slug}/comments', 'store')->middleware('auth:api');
            Route::delete('{slug}/comments/{id}', 'destroy')->middleware('auth:api'); //needs authorization
        });

    });

Route::get('/followers', [AuthController::class, "getFollowers"]);
Route::get('/followings', [AuthController::class, "getFollowings"]);

Route::prefix('profiles')
    ->controller(UserController::class)
    ->group(function () {
        Route::get('/{username}', 'show');
        Route::get('/{viewUserId}/checkIsFollowed', 'checkIsFollowed');
        Route::post('{username}/follow', 'followUser')->middleware('auth:api');
        Route::delete('{username}/follow', 'unfollowUser')->middleware('auth:api');
    });

//
