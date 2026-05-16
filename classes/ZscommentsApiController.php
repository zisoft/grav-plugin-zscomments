<?php

declare(strict_types=1);

namespace Grav\Plugin\Zscomments;

use Grav\Common\Plugins;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ApiException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ZscommentsApiController extends AbstractApiController
{
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');

        $query = $request->getQueryParams();
        $page = max(0, (int) ($query['page'] ?? 0));
        $filters = [
            'range' => (string) ($query['range'] ?? '7d'),
            'pending_only' => !empty($query['pending_only']) && $query['pending_only'] !== '0',
            'route' => (string) ($query['route'] ?? ''),
            'search' => (string) ($query['search'] ?? ''),
        ];

        return ApiResponse::create($this->getZscommentsPlugin()->getAdminPageData($page, $filters));
    }

    public function approve(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');

        $body = $this->getRequestBody($request);
        $id = trim((string) ($body['id'] ?? ''));
        $url = trim((string) ($body['url'] ?? ''));
        $lang = isset($body['lang']) ? trim((string) $body['lang']) : null;
        $quickreply = trim(strip_tags((string) ($body['quickreply'] ?? '')));

        if ($id === '' || $url === '') {
            throw new ValidationException('Fields "id" and "url" are required.');
        }

        $updated = $this->getZscommentsPlugin()->performCommentAction('approve', $id, $url, $lang, $quickreply);

        if (!$updated) {
            throw new ApiException(404, 'Not Found', 'Comment not found.');
        }

        return ApiResponse::create([
            'message' => 'Comment approved successfully.',
            'id' => $id,
            'url' => $url,
        ]);
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.super');

        $body = $this->getRequestBody($request);
        $id = trim((string) ($body['id'] ?? ''));
        $url = trim((string) ($body['url'] ?? ''));
        $lang = isset($body['lang']) ? trim((string) $body['lang']) : null;

        if ($id === '' || $url === '') {
            throw new ValidationException('Fields "id" and "url" are required.');
        }

        $updated = $this->getZscommentsPlugin()->performCommentAction('delete', $id, $url, $lang);

        if (!$updated) {
            throw new ApiException(404, 'Not Found', 'Comment not found.');
        }

        return ApiResponse::create([
            'message' => 'Comment deleted successfully.',
            'id' => $id,
            'url' => $url,
        ]);
    }

    private function getZscommentsPlugin(): \Grav\Plugin\ZscommentsPlugin
    {
        $plugin = Plugins::getPlugin('zscomments');

        if (!$plugin instanceof \Grav\Plugin\ZscommentsPlugin) {
            throw new ApiException(503, 'Service Unavailable', 'ZSComments plugin is not available.');
        }

        return $plugin;
    }
}
