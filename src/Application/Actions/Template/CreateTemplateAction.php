<?php
declare(strict_types=1);

namespace App\Application\Actions\Template;

use App\Application\Actions\Action;
use App\Application\Services\PlaceholderExtractor;
use App\Application\Services\CloudinaryService; // <-- Add this import
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
        private CloudinaryService    $cloudinaryService // <-- Inject CloudinaryService
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
                'name'             => v::notEmpty()->stringType()->length(1, 150),
                'category'         => v::optional(v::in([
                    'id_card','certificate','invite','badge',
                    'business_card','ticket','label','other',
                ])),
                'canvas_json'      => v::notEmpty()->stringType(),
                'width_px'         => v::optional(v::intType()->positive()),
                'height_px'        => v::optional(v::intType()->positive()),
                'is_public'        => v::optional(v::boolType()),
                // Accept the base64 string from the frontend
                'thumbnail_base64' => v::optional(v::stringType()), 
            ]
        );

        $placeholders = $this->extractor->extract($data['canvas_json']);

        // Handle the Cloudinary Upload
        $thumbnailUrl = null;
        if (!empty($data['thumbnail_base64'])) {
            // The Cloudinary SDK natively accepts Data URIs (Base64) in place of file paths
            $uploadResult = $this->cloudinaryService->upload(
                $data['thumbnail_base64'], // The base64 string
                'bdp/templates/thumbnails', // Cloudinary folder
                uniqid('thumb_')            // Random public ID
            );
            $thumbnailUrl = $uploadResult['secure_url'];
        }

        $template = $this->templates->create([
            'user_id'       => $user->getId(),
            'name'          => $data['name'],
            'category'      => $data['category']     ?? 'other',
            'canvas_json'   => $data['canvas_json'],
            'placeholders'  => $placeholders,
            'width_px'      => $data['width_px']     ?? 800,
            'height_px'     => $data['height_px']    ?? 600,
            'thumbnail_url' => $thumbnailUrl, // Save the generated URL
            'is_public'     => $data['is_public']    ?? false,
        ]);

        return $this->respondWithData($template->jsonSerialize(), 201);
    }
}