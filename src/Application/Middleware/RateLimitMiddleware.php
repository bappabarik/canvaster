<?php
declare(strict_types=1);

namespace App\Application\Middleware;

use App\Application\Settings\SettingsInterface;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private PDO               $pdo,
        private SettingsInterface $settings,
    ) {}

    public function process(Request $request, Handler $handler): Response
    {
        $config = $this->settings->get('rate_limit');
        $ip     = $this->getIp($request);
        $path   = $request->getUri()->getPath();

        // Auth routes get stricter limit
        $isAuthRoute = str_starts_with($path, '/api/v1/auth/');
        $limit       = $isAuthRoute ? $config['auth_per_minute'] : $config['guest_per_minute'];
        $identifier  = 'ip:' . $ip . ($isAuthRoute ? ':auth' : '');

        // Authenticated user gets higher limit
        $userId = $request->getAttribute('user_id');
        if ($userId) {
            $identifier = 'user:' . $userId;
            $limit      = $config['user_per_minute'];
        }

        if (!$this->isAllowed($identifier, $limit)) {
            $response = new SlimResponse(429);
            $response->getBody()->write(json_encode([
                'statusCode' => 429,
                'error' => [
                    'type'        => 'RATE_LIMIT_EXCEEDED',
                    'description' => 'Too many requests. Please slow down.',
                ],
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }

    private function isAllowed(string $identifier, int $limit): bool
    {
        // Count hits in the last 60 seconds
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM rate_limit_hits
             WHERE identifier = ? AND hit_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)'
        );
        $stmt->execute([$identifier]);
        $count = (int) $stmt->fetchColumn();

        if ($count >= $limit) {
            return false;
        }

        // Record this hit
        $this->pdo->prepare(
            'INSERT INTO rate_limit_hits (identifier) VALUES (?)'
        )->execute([$identifier]);

        // Cleanup old rows periodically (1% of requests)
        if (random_int(1, 100) === 1) {
            $this->pdo->prepare(
                'DELETE FROM rate_limit_hits WHERE hit_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)'
            )->execute();
        }

        return true;
    }

    private function getIp(Request $request): string
    {
        $params = $request->getServerParams();
        return $params['HTTP_X_FORWARDED_FOR']
            ?? $params['REMOTE_ADDR']
            ?? 'unknown';
    }
}