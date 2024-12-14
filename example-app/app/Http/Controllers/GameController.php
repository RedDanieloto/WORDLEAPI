<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use App\Models\Game;
use App\Jobs\SendGameSummaryToSlack;
use Twilio\Rest\Client;

class GameController extends Controller
{
    protected $twilio;

    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->twilio = new Client(env('TWILIO_SID'), env('TWILIO_AUTH_TOKEN'));
    }

    // Crear una nueva partida
    public function create(Request $request)
    {
        $user = Auth::user(); // Usuario autenticado

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
        ], [
            'user_id.required' => 'El campo user_id es obligatorio.',
            'user_id.exists' => 'El ID proporcionado no existe en la base de datos. Tu ID es ' . $user->id,
        ]);

        if ($user->id !== $data['user_id']) {
            return response()->json([
                'mensaje' => 'El ID proporcionado es incorrecto. Tu ID es ' . $user->id,
            ], 403);
        }

        $palabra = null;
        $intentos = 5;

        while ($intentos > 0) {
            $response = Http::get('https://clientes.api.greenborn.com.ar/public-random-word');
            if ($response->successful()) {
                $palabraCandidata = trim($response->body(), '[]" ');
                if (strlen($palabraCandidata) >= 4 && strlen($palabraCandidata) <= 8) {
                    $palabra = $palabraCandidata;
                    break;
                }
            }
            $intentos--;
        }

        if (!$palabra) {
            return response()->json(['mensaje' => 'No se pudo obtener una palabra válida después de varios intentos.'], 500);
        }

        $existingGame = Game::where('user_id', $user->id)->where('is_active', true)->first();
        if ($existingGame) {
            return response()->json(['mensaje' => 'Ya tienes una partida activa.'], 400);
        }

        $game = Game::create([
            'user_id' => $user->id,
            'word' => $palabra,
            'remaining_attempts' => env('WORDLE_MAX_ATTEMPTS', 5),
        ]);

        try {
            $this->twilio->messages->create(
                "whatsapp:" . $user->phone,
                [
                    'from' => 'whatsapp:' . env('TWILIO_WHATSAPP_NUMBER'),
                    'body' => "Su partida se ha creado correctamente. La palabra a jugar tiene " . strlen($palabra) . " letras.",
                ]
            );
        } catch (\Exception $e) {
            return response()->json(['mensaje' => 'Error al enviar mensaje de WhatsApp: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'mensaje' => 'Su partida se ha creado correctamente. La palabra a jugar tiene ' . strlen($palabra) . ' letras.',
            'juego' => [
                'id' => $game->id,
                'user_id' => $game->user_id,
                'intentos_restantes' => $game->remaining_attempts,
                'creado_en' => $game->created_at,
                'actualizado_en' => $game->updated_at,
            ],
        ]);
    }

    // Enviar un intento de palabra
    public function guess(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'game_id' => 'required|exists:games,id',
            'word' => ['required', 'string', 'regex:/^[a-zA-Z]+$/'], // Validar solo letras
        ], [
            'game_id.required' => 'El campo game_id es obligatorio.',
            'game_id.exists' => 'El juego especificado no existe.',
            'word.required' => 'El campo palabra es obligatorio.',
            'word.regex' => 'La palabra solo puede contener letras.',
        ]);

        $game = Game::find($data['game_id']);

        if (!$game || !$game->is_active) {
            $activeGame = Game::where('user_id', $user->id)->where('is_active', true)->first();
            $mensaje = $activeGame
                ? [
                    'mensaje' => 'El ID de la partida que querías no existe o ya ha acabado.',
                    'partida_activa' => [
                        'id' => $activeGame->id,
                        'creada_en' => $activeGame->created_at,
                        'intentos_restantes' => $activeGame->remaining_attempts,
                    ],
                ]
                : ['mensaje' => 'No tienes partidas activas.'];

            return response()->json($mensaje, 404);
        }

        if ($game->user_id !== $user->id) {
            return response()->json(['mensaje' => 'No tienes permiso para realizar esta acción.'], 403);
        }

        $attempt = $data['word'];
        $correctWord = $game->word;

        if (strlen($attempt) !== strlen($correctWord)) {
            return response()->json(['mensaje' => 'La palabra debe tener exactamente ' . strlen($correctWord) . ' caracteres.'], 400);
        }

        $pistas = '';
        for ($i = 0; $i < strlen($correctWord); $i++) {
            $pistas .= (isset($attempt[$i]) && $attempt[$i] === $correctWord[$i]) ? $correctWord[$i] : '-';
        }

        $game->attempts()->create([
            'word' => $attempt,
            'is_correct' => $attempt === $correctWord,
        ]);

        if ($attempt === $correctWord) {
            $game->update(['is_active' => false]);
            SendGameSummaryToSlack::dispatch($game, 'Ganado')->delay(now()->addMinute());

            try {
                $this->twilio->messages->create(
                    "whatsapp:" . $user->phone,
                    [
                        'from' => 'whatsapp:' . env('TWILIO_WHATSAPP_NUMBER'),
                        'body' => "¡Felicidades! Has ganado la partida. La palabra era: $correctWord.",
                    ]
                );
            } catch (\Exception $e) {
                return response()->json(['mensaje' => 'Error al enviar mensaje de WhatsApp: ' . $e->getMessage()], 500);
            }

            return response()->json(['mensaje' => '¡Correcto! Ganaste el juego.']);
        }

        $game->decrement('remaining_attempts');

        if ($game->remaining_attempts <= 0) {
            $game->update(['is_active' => false]);
            SendGameSummaryToSlack::dispatch($game, 'Perdido')->delay(now()->addMinute());

            try {
                $this->twilio->messages->create(
                    "whatsapp:" . $user->phone,
                    [
                        'from' => 'whatsapp:' . env('TWILIO_WHATSAPP_NUMBER'),
                        'body' => "Has perdido la partida. La palabra correcta era: $correctWord.",
                    ]
                );
            } catch (\Exception $e) {
                return response()->json(['mensaje' => 'Error al enviar mensaje de WhatsApp: ' . $e->getMessage()], 500);
            }

            return response()->json(['mensaje' => 'Has perdido el juego. La palabra era: ' . $correctWord]);
        }

        try {
            $this->twilio->messages->create(
                "whatsapp:" . $user->phone,
                [
                    'from' => 'whatsapp:' . env('TWILIO_WHATSAPP_NUMBER'),
                    'body' => "Intento: $attempt | Pistas: $pistas. Intentos restantes: {$game->remaining_attempts}.",
                ]
            );
        } catch (\Exception $e) {
            return response()->json(['mensaje' => 'Error al enviar mensaje de WhatsApp: ' . $e->getMessage()], 500);
        }

        return response()->json(['mensaje' => 'Intenta de nuevo.', 'intentos_restantes' => $game->remaining_attempts]);
    }

    // Abandonar la partida actual
    public function abandon(Request $request)
    {
        $user = Auth::user();

        $game = Game::where('user_id', $user->id)->where('is_active', true)->first();

        if (!$game) {
            return response()->json(['mensaje' => 'No tienes ninguna partida activa para abandonar.'], 404);
        }

        $game->update(['is_active' => false]);
        $game->attempts()->create(['word' => 'abandonado', 'is_correct' => false]);

        SendGameSummaryToSlack::dispatch($game, 'Abandonado')->delay(now()->addMinute());

        try {
            $this->twilio->messages->create(
                "whatsapp:" . $user->phone,
                [
                    'from' => 'whatsapp:' . env('TWILIO_WHATSAPP_NUMBER'),
                    'body' => "Has abandonado la partida. La palabra correcta era: {$game->word}.",
                ]
            );
        } catch (\Exception $e) {
            return response()->json(['mensaje' => 'Error al enviar mensaje de WhatsApp: ' . $e->getMessage()], 500);
        }

        return response()->json(['mensaje' => 'Has abandonado la partida.']);
    }

    // Consultar la partida actual
    public function current(Request $request)
    {
        $user = Auth::user();

        $game = Game::where('user_id', $user->id)
            ->where('is_active', true)
            ->with(['attempts' => function ($query) {
                $query->select('id', 'game_id', 'word', 'is_correct', 'created_at');
            }])
            ->first();

        if (!$game) {
            return response()->json(['mensaje' => 'No tienes ninguna partida activa.'], 404);
        }

        $attemptsWithHints = $game->attempts->map(function ($attempt) use ($game) {
            $correctWord = $game->word;
            $attemptWord = $attempt->word;
            $hints = '';
            for ($i = 0; $i < strlen($correctWord); $i++) {
                $hints .= (isset($attemptWord[$i]) && $attemptWord[$i] === $correctWord[$i]) ? $correctWord[$i] : '-';
            }
            return [
                'intento' => $attemptWord,
                'pistas' => $hints,
                'es_correcto' => $attempt->is_correct,
                'creado_en' => $attempt->created_at,
            ];
        });

        $response = [
            'id' => $game->id,
            'user_id' => $game->user_id,
            'intentos_restantes' => $game->remaining_attempts,
            'activo' => $game->is_active,
            'intentos' => $attemptsWithHints,
            'creado_en' => $game->created_at,
            'actualizado_en' => $game->updated_at,
        ];

        return response()->json(['juego' => $response]);
    }

    // Consultar el historial de juegos
    public function history(Request $request)
    {
        $user = Auth::user();

        $games = Game::where('user_id', $user->id)
            ->with('attempts')
            ->get();

        if ($games->isEmpty()) {
            return response()->json(['mensaje' => 'No tienes partidas registradas.'], 404);
        }

        return response()->json(['juegos' => $games]);
    }
}