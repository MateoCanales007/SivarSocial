<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Exception;

class RecoverController extends Controller
{
    /**
     * muestra la vista recuperar
     */

    /**
     * PASO 1
     * */
    public function index()
    {
        return view('auth.recover.recuperar');
    }

    /**
     * PASO 2 - LA VALIDACIÓN
     * */
    public function enviarCodigo(Request $request)
    {
        try {
            $request->validate([
                'email' => ['required', 'email'],
            ]);

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return redirect()->back()
                    ->withInput()
                    ->with('status', 'El correo no existe en nuestros registros.');
            }

            // Genera un codigo random código
            $codigo = random_int(100000, 999999);

            // Guarda en la sesión
            Session::put('codigo_verificacion', $codigo);
            Session::put('email_verificacion', $request->email);

            // Encargado de enviar correo al usuario destino
            try {
                Mail::send([], [], function ($message) use ($request, $codigo) {
                    $html = view('emails.codigo', [
                        'codigo' => $codigo,
                        'email' => $request->email
                    ])->render();
                    $message->to($request->email)
                        ->subject('Tu código de recuperación de contraseña es: ' . $codigo)
                        ->html($html);
                });

                Log::info('Correo de recuperación enviado exitosamente', [
                    'email' => $request->email,
                    'codigo' => $codigo
                ]);
            } catch (Exception $e) {
                Log::error('Error al enviar correo de recuperación', [
                    'email' => $request->email,
                    'error' => $e->getMessage()
                ]);

                // En caso de error del correo, continuamos sin enviar
                // pero guardamos el código para pruebas
            }

            // Redirige a la vista de verificación
            return redirect()->route('code.verific');
        } catch (Exception $e) {
            Log::error('Error en enviarCodigo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()->back()
                ->withInput()
                ->with('status', 'Ha ocurrido un error. Por favor, inténtalo de nuevo.');
        }
    }

    /**
     * PASO 3
     * */
    public function index2()
    {
        if (!Session::has('codigo_verificacion')) {
            return redirect()->route('recuperar')->with('status', 'No se ha solicitado recuperación');
        }

        return view('auth.recover.code-verific');
    }

    /**
     * PASO 4 - VERIFICACIÓN
     * */
    public function validarCodigo(Request $request)
    {
        try {
            // Valida que se haya enviado el código
            $request->validate([
                'codigo' => ['required', 'digits:6'],
            ]);

            // Obtener el código esperado desde la sesión
            $codigoEsperado = Session::get('codigo_verificacion');
            $correo = Session::get('email_verificacion');

            /**
             *  ¿CODIGO INGRESADO ES = A CODIGO ENVIADO?
             * */

            if ($request->codigo == $codigoEsperado) {
                // Código correcto
                Session::put('codigo_verificado', true);
                return redirect()->route('restablecer');
            } else {
                // Código incorrecto
                return back()->with('status', 'El código ingresado es incorrecto')->withInput();
            }
        } catch (Exception $e) {
            Log::error('Error en validarCodigo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return back()->with('status', 'Ha ocurrido un error. Por favor, inténtalo de nuevo.');
        }
    }

    /**
     * PASO 5
     * */
    public function index3()
    {
        if (!Session::has('codigo_verificado')) {
            return redirect()->route('code.verific')->with('status', 'No se ha solicitado recuperación');
        }
        return view('auth.recover.restablecer');
    }


    /**
     * PASO 6 - CAMBIO DE CONTRASEÑA
     * */
    public function restablecer(Request $request)
    {
        try {
            // Validar contraseña segura
            $request->validate([
                'password' => [
                    'required',
                    'confirmed',
                    'min:8',
                    'regex:/[a-z]/',
                    'regex:/[A-Z]/',
                    'regex:/[0-9]/',
                    'regex:/[\W_]/',
                ],
            ], [
                'password.confirmed' => 'Las contraseñas no coinciden.',
                'password.regex' => 'La contraseña debe tener mayúsculas, minúsculas, números y un carácter especial.',
            ]);

            // Se hace la petición para obtener correo
            $correo = Session::get('email_verificacion');

            if (!$correo) {
                return redirect()->route('recuperar')->with('status', 'No se ha solicitado recuperación.');
            }

            // Buscar el usuario de la petición por su email
            $usuario = User::where('email', $correo)->first();

            if (!$usuario) {
                return redirect()->route('recuperar')->with('status', 'El usuario no fue encontrado.');
            }

            // Actualizar la contraseña con su debido hash
            $usuario->password = Hash::make($request->password);
            $usuario->save();

            // Intentar enviar correo de confirmación
            try {
                $html = view('emails.verificacion', [
                    'email' => $correo
                ])->render();

                Mail::send([], [], function ($message) use ($correo, $html) {
                    $hora = now()->format('H:i');
                    $message->to($correo)
                        ->subject("🔔 de seguridad: cambio de contraseña - $hora")
                        ->html($html);
                });

                Log::info('Correo de confirmación enviado exitosamente', [
                    'email' => $correo
                ]);
            } catch (Exception $e) {
                Log::error('Error al enviar correo de confirmación', [
                    'email' => $correo,
                    'error' => $e->getMessage()
                ]);
                // Continuamos aunque falle el envío del correo
            }

            // Borra sessiones de estos pasos
            Session::forget(['email_verificacion', 'codigo_verificacion', 'codigo_verificado']);

            // Redirigir al login u otra ruta con éxito
            return redirect()->route('login')->with('success', 'Tu contraseña fue actualizada con éxito.');
        } catch (Exception $e) {
            Log::error('Error en restablecer', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return back()->with('status', 'Ha ocurrido un error. Por favor, inténtalo de nuevo.');
        }
    }
}
