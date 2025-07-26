<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->only('email', 'password');

        if (auth()->attempt($credentials)) {
            $user = auth()->user();
            $token = $user->createToken('auth_token')->plainTextToken;
            $user->save();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'user' => $user,
                    'token' => $token,
                ],
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'Invalid credentials',
        ], 401);
    }

    public function logout(Request $request)
    {
        Auth::logout();

        return response()->json([
            'status' => 'success',
            'message' => 'Logged out successfully',
        ]);
    }

    public function create(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'role'=>'required|string',
            'location'=>'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);
        $user = new User();
        $user->name = $request->name;
        $user->email = $request->email;
        $user->role= $request->role;
        $user->location=$request->location;
        $user->password = bcrypt($request->password);
        $user->save();

        return response()->json([
            'status' => 'success',
            'data' => $user,
        ]);
    }

    public function index()
    {
        $users = User::orderBy('name', 'asc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $users,
        ]);
    }

    public function updateUser(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|string|email|max:255|unique:users,email,' . $user->id,
            'role' => 'sometimes|required|string',
            'location' => 'sometimes|required|string',
        ]);

        $user->name = $request->name;
        $user->email = $request->email;
        $user->role = $request->role;
        $user->location = $request->location;
        if($request->password){
            $user->password = bcrypt($request->password);
        }

        $user->save();

        return response()->json([
            'status' => 'success',
            'data' => $user,
        ]);
    }
    public function deleteUser($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
            ], 404);
        }

        $user->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'User deleted successfully',
        ]);
    }


}
