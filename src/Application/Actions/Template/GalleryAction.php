<?php
declare(strict_types=1);

namespace App\Application\Actions\Template;

use App\Application\Actions\Action;
use App\Domain\Template\TemplateRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class GalleryAction extends Action
{
    public function __construct(
        LoggerInterface    $logger,
        private TemplateRepository $templates,
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $params   = $this->request->getQueryParams();
        $category = $params['category'] ?? '';
        $tag      = $params['tag']      ?? '';

        $templates = $this->templates->findGallery($category, $tag);

        return $this->respondWithData([
            'templates' => array_map(fn($t) => $t->jsonSerialize(), $templates),
            'total'     => count($templates),
        ]);
    }
}