<?php
namespace App\Http\Intermediarios;
use App\Servicios\Privacidad\ClientPublicRepresentation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class ApiResponsePrivacy
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if (! $response instanceof JsonResponse || ! $request->is('api/*')) return $response;
        $data = json_decode($response->getContent(), true);
        if (! is_array($data)) return $response;
        $data = $this->removeRecoverablePasswords($data);
        if ($request->is('api/v1/cliente/*', 'api/v1/client/*', 'api/v1/public/aircraft-preview')
            || ($request->user()?->hasRole('client') && ! $request->user()?->hasRole('admin'))) {
            $data = ClientPublicRepresentation::sanitize($data);
        }
        $response->setData($data);
        return $response;
    }

    private function removeRecoverablePasswords(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, ['password', 'temporary_password_visible', 'temporary_password', 'plain_password', 'generated_password', 'raw_password'], true)) {
                unset($data[$key]);
            } elseif (is_array($value)) {
                $data[$key] = $this->removeRecoverablePasswords($value);
            }
        }
        return $data;
    }
}
