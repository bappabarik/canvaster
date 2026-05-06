<?php
declare(strict_types=1);

namespace App\Application\Actions\Asset;

use App\Application\Actions\Action;
use App\Domain\Asset\AssetRepository;
use App\Domain\User\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ListAssetsAction extends Action
{
    public function __construct(
        LoggerInterface       $logger,
        private AssetRepository $assets,
    ) {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        /** @var User $user */
        $user = $this->request->getAttribute('user');

        // Optional ?type=csv_file|row_image|zip_extract|template_bg filter
        $type = $this->request->getQueryParams()['type'] ?? '';

        $assets = $this->assets->findByUser($user->getId(), $type);

        // Group by asset_type for Canva-style panel display
        $grouped = [];
        foreach ($assets as $asset) {
            $grouped[$asset->getAssetType()][] = $asset->jsonSerialize();
        }

        return $this->respondWithData([
            'total'  => count($assets),
            'assets' => $grouped,
        ]);
    }
}