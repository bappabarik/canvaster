<?php
declare(strict_types=1);

namespace App\Application\Actions\Template;

use App\Application\Actions\Action;
use App\Application\Services\PlaceholderExtractor;
use App\Application\Validation\RequestValidator;
use App\Domain\Template\TemplateRepository;
use App\Domain\User\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Respect\Validation\Validator as v;

class CreateTemplateAction extends Action
{
    public function __construct(
        LoggerInterface      $logger,
        private TemplateRepository   $templates,
        private PlaceholderExtractor $extractor,
        private RequestValidator     $validator,
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        /** @var User $user */
        $user = $this->request->getAttribute('user');

        $data = $this->validator->validate(
            (array) $this->request->getParsedBody(),
            [
                'name'          => v::notEmpty()->stringType()->length(1, 150),
                'category'      => v::optional(v::in([
                    'id_card','certificate','invite','badge',
                    'business_card','ticket','label','other',
                ])),
                'canvas_json'   => v::notEmpty()->stringType(),
                'width_px'      => v::optional(v::intType()->positive()),
                'height_px'     => v::optional(v::intType()->positive()),
                'thumbnail_url' => v::optional(v::url()),
                'is_public'     => v::optional(v::boolType()),
            ]
        );

        // Extract placeholders from canvas JSON server-side
        $placeholders = $this->extractor->extract($data['canvas_json']);

        $template = $this->templates->create([
            'user_id'       => $user->getId(),
            'name'          => $data['name'],
            'category'      => $data['category']     ?? 'other',
            'canvas_json'   => $data['canvas_json'],
            'placeholders'  => $placeholders,
            'width_px'      => $data['width_px']     ?? 800,
            'height_px'     => $data['height_px']    ?? 600,
            'thumbnail_url' => $data['thumbnail_url'] ?? null,
            'is_public'     => $data['is_public']    ?? false,
        ]);

        return $this->respondWithData($template->jsonSerialize(), 201);
    }
}