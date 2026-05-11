<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PasswordController extends Controller
{
    public function edit()
    {
        return view('auth.password');
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password'         => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'current_password.current_password' => 'La contraseña actual es incorrecta.',
            'password.min'       => 'La nueva contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'La confirmación no coincide.',
        ]);

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $user->password = $data['password']; // cast 'hashed' del modelo se encarga
        $user->save();

        return redirect()->route('password.edit')->with('ok', 'Contraseña actualizada.');
    }
}
