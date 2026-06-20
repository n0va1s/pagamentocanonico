<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! $request->user()) {
            abort(401);
        }

        if (! $request->user()->hasRole($roles)) {
            // Se o usuário for um membro comum tentando acessar o painel de gestão, redireciona amigavelmente
            if ($request->user()->isMembro()) {
                return redirect()->route('minha-associacao');
            }

            abort(403, 'Acesso não autorizado.');
        }

        return $next($request);
    }
}
