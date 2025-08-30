<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PostController extends Controller
{
    /* para proteger que no se pueda abrir el muro en otra página */
    public function __construct()
    {
        $this->middleware('auth')->except(['index', 'show']);
    }

    public function index(User $user)
    {
        // Verificar si el usuario existe
        if (!$user->exists) {
            abort(404, 'Usuario no encontrado');
        }

        $postsPerPage = config('pagination.posts_per_page', 6);
        $authUser = Auth::user();

        // Filtrar posts según la visibilidad y relación con el usuario autenticado
        $postsQuery = $user->posts();

        if (!$authUser || $authUser->id !== $user->id) {
            // Si no está autenticado o es otro usuario, filtrar por visibilidad
            $postsQuery->where(function ($query) use ($authUser, $user) {
                // Siempre mostrar publicaciones públicas
                $query->where('visibility', 'public');
                
                // Si está autenticado y sigue al usuario, mostrar también las privadas
                if ($authUser && $authUser->following->contains('id', $user->id)) {
                    $query->orWhere('visibility', 'followers');
                }
            });
        }
        // Si es el dueño del perfil, mostrar todas sus publicaciones

        $posts = $postsQuery
            ->with('comentarios')
            ->latest()
            ->paginate($postsPerPage);

        // Obtener el total de publicaciones visibles para el usuario actual
        $totalPosts = $postsQuery->count();

        $users = \App\Models\User::latest()->get();

        // Verifica si el usuario autenticado es el mismo que el del muro
        return view('layouts.dashboard', [
            'user' => $user,
            'authUser' => $authUser,
            'posts' => $posts,
            'totalPosts' => $totalPosts,
            'users' => $users,
        ]);
    }

    public function create()
    {
        $users = User::latest()->get();
        $authUser = Auth::user();
        return view('posts.create', [
            'users' => $users,
            'authUser' => $authUser,
        ]);
    }

    public function store(Request $request)
    {
        // Validación condicional según el tipo
        if ($request->tipo === 'imagen') {
            $request->validate([
                'titulo' => 'required|max:255',
                'descripcion' => 'required',
                'tipo' => 'required|in:imagen,musica',
                'visibility' => 'required|in:public,followers',
                'imagen' => 'required|string',
            ]);
        } else if ($request->tipo === 'musica') {
            $request->validate([
                'titulo' => 'nullable|max:255',
                'descripcion' => 'nullable',
                'tipo' => 'required|in:imagen,musica',
                'visibility' => 'required|in:public,followers',
                'music_source' => 'required|in:itunes,spotify',
                // Campos iTunes
                'itunes_track_id' => 'nullable|string',
                'itunes_track_name' => 'nullable|string',
                'itunes_artist_name' => 'nullable|string',
                'itunes_collection_name' => 'nullable|string',
                'itunes_artwork_url' => 'nullable|string',
                'itunes_preview_url' => 'nullable|string',
                'itunes_track_view_url' => 'nullable|string',
                'itunes_track_time_millis' => 'nullable|integer',
                'itunes_country' => 'nullable|string',
                'itunes_primary_genre_name' => 'nullable|string',
            ]);

            // Validar que tenga datos de iTunes
            if (empty($request->itunes_track_id)) {
                return back()->withErrors(['music' => 'Debes seleccionar una canción'])->withInput();
            }
        }

        $postData = [
            'titulo' => $request->titulo,
            'descripcion' => $request->descripcion,
            'tipo' => $request->tipo,
            'visibility' => $request->visibility,
            'user_id' => Auth::id(),
        ];

        // Añadir campos específicos según el tipo
        if ($request->tipo === 'imagen') {
            $postData['imagen'] = $request->imagen;
        } else if ($request->tipo === 'musica') {
            $postData['music_source'] = $request->music_source ?? 'itunes';

            // Campos iTunes
            if ($request->music_source === 'itunes' || !empty($request->itunes_track_id)) {
                $postData['itunes_track_id'] = $request->itunes_track_id;
                $postData['itunes_track_name'] = $request->itunes_track_name;
                $postData['itunes_artist_name'] = $request->itunes_artist_name;
                $postData['itunes_collection_name'] = $request->itunes_collection_name;
                $postData['itunes_artwork_url'] = $request->itunes_artwork_url;
                $postData['itunes_preview_url'] = $request->itunes_preview_url;
                $postData['itunes_track_view_url'] = $request->itunes_track_view_url;
                $postData['itunes_track_time_millis'] = $request->itunes_track_time_millis;
                $postData['itunes_country'] = $request->itunes_country;
                $postData['itunes_primary_genre_name'] = $request->itunes_primary_genre_name;

                // Generar enlaces cruzados a Spotify para canciones de iTunes
                $searchTerms = \App\Services\CrossPlatformMusicService::cleanSearchTerms(
                    $request->itunes_artist_name,
                    $request->itunes_track_name
                );
                $postData['artist_search_term'] = $searchTerms['artist'];
                $postData['track_search_term'] = $searchTerms['track'];
                $postData['spotify_web_url'] = \App\Services\CrossPlatformMusicService::generateSpotifySearchUrl(
                    $searchTerms['artist'],
                    $searchTerms['track']
                );
            }
        }

        Post::create($postData);

        return redirect()->route('posts.index', ['user' => Auth::user()])
            ->with('success', 'Post creado correctamente');
    }

    public function show(User $user, Post $post)
    {
        // Verificar si el usuario existe
        if (!$user->exists) {
            abort(404, 'Usuario no encontrado');
        }

        // Verificar si el post existe
        if (!$post->exists) {
            abort(404, 'Publicación no encontrada');
        }

        // Verificar que el post pertenece al usuario
        if ($post->user_id !== $user->id) {
            abort(404, 'La publicación no pertenece a este usuario');
        }

        // Verificar si el usuario actual puede ver esta publicación
        $authUser = Auth::user();
        if (!$post->canBeViewedBy($authUser)) {
            // Si es solo para seguidores y no está autenticado, redirigir al login
            if (!$authUser && $post->isForFollowersOnly()) {
                return redirect()->route('login')->with('message', 'Inicia sesión para ver esta publicación');
            }
            // Si está autenticado pero no puede verla, mostrar error 403
            abort(403, 'No tienes permisos para ver esta publicación');
        }

        // Cargar las relaciones necesarias para el post
        $post->load(['likes', 'comentarios', 'user']);

        $users = \App\Models\User::latest()->get();
        return view('posts.show', [
            'post' => $post,
            'user' => $user,
            'authUser' => $authUser,
            'users' => $users,
        ]);
    }

    public function destroy(Post $post)
    {
        $this->authorize('delete', $post);

        // Eliminar la imagen ANTES de eliminar el post
        if ($post->imagen && !empty($post->imagen)) {
            $imagePath = public_path('uploads/' . $post->imagen);
            if (file_exists($imagePath) && is_file($imagePath)) {
                unlink($imagePath);
            }
        }

        // Eliminar el post después de eliminar la imagen
        $post->delete();

        // Redirigir al muro del usuario autenticado
        return redirect()->route('posts.index', ['user' => Auth::user()])
            ->with('success', 'Post eliminado correctamente');
    }

    public function getLikes(Request $request, Post $post)
    {
        try {
            // Parámetros de paginación
            $page = max(1, (int) $request->get('page', 1));
            $perPage = min(50, max(10, (int) $request->get('per_page', 20))); // Limitar entre 10 y 50
            $offset = ($page - 1) * $perPage;

            // Obtener total de likes para paginación
            $totalLikes = $post->likes()->count();

            // Obtener likes paginados con información del usuario ordenados por más recientes
            $likes = $post->likes()
                ->with(['user:id,name,username,imagen,profession'])
                ->latest() // Ordenar por más recientes
                ->offset($offset)
                ->limit($perPage)
                ->get()
                ->map(function ($like) {
                    $isFollowing = false;

                    // Solo verificar estado de seguimiento si hay usuario autenticado
                    if (Auth::check() && Auth::id() !== $like->user->id) {
                        try {
                            // Verificar si el usuario autenticado sigue a este usuario
                            $isFollowing = DB::table('followers')
                                ->where('follower_id', Auth::id())
                                ->where('user_id', $like->user->id)
                                ->exists();
                        } catch (\Exception $e) {
                            // Si hay error en la verificación de seguimiento, continuar sin ese dato
                            $isFollowing = false;
                        }
                    }

                    return [
                        'id' => $like->id,
                        'user' => [
                            'id' => $like->user->id,
                            'name' => $like->user->name,
                            'username' => $like->user->username,
                            'imagen' => $like->user->imagen,
                            'profession' => $like->user->profession ?? null,
                            'verified' => $like->user->verified ?? false, // Campo para futuro uso
                        ],
                        'isFollowing' => $isFollowing,
                        'created_at' => $like->created_at->toDateTimeString(),
                    ];
                });

            // Calcular metadatos de paginación
            $hasMore = ($offset + $perPage) < $totalLikes;
            $totalPages = ceil($totalLikes / $perPage);

            return response()->json([
                'success' => true,
                'likes' => $likes,
                'total' => $totalLikes,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => $totalPages,
                    'has_more' => $hasMore,
                    'from' => $offset + 1,
                    'to' => min($offset + $perPage, $totalLikes),
                ]
            ]);
        } catch (\Exception $e) {
            // Log del error para debugging
            Log::error('Error in getLikes: ' . $e->getMessage(), [
                'post_id' => $post->id,
                'page' => $request->get('page'),
                'per_page' => $request->get('per_page'),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al cargar los likes: ' . $e->getMessage(),
                'likes' => [],
                'total' => 0,
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => $perPage ?? 20,
                    'total_pages' => 0,
                    'has_more' => false,
                    'from' => 0,
                    'to' => 0,
                ]
            ], 500);
        }
    }
}
