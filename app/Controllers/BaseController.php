<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * BaseController provides a convenient place for loading components
 * and performing functions that are needed by all your controllers.
 *
 * Extend this class in any new controllers:
 * ```
 *     class Home extends BaseController
 * ```
 *
 * For security, be sure to declare any new methods as protected or private.
 */
abstract class BaseController extends Controller
{
    /**
     * Be sure to declare properties for any property fetch you initialized.
     * The creation of dynamic property is deprecated in PHP 8.2.
     */

    // protected $session;

    /**
     * @return void
     */
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        // Load here all helpers you want to be available in your controllers that extend BaseController.
        // Caution: Do not put the this below the parent::initController() call below.
        $this->helpers = ['url'];

        // Caution: Do not edit this line.
        parent::initController($request, $response, $logger);

        // Preload any models, libraries, etc, here.
        // $this->session = service('session');
    }

    /**
     * Links the pending terms AI tag suggestion stored while the form was filled
     * in (posted as proposals[]) to the gig / showcase just saved, so a reviewer
     * sees where they came from and a rejection reaches its owner. Never fails
     * the save.
     */
    protected function attachSuggestedTerms(string $ownerType, int $ownerId): void
    {
        $ids = array_values(array_filter(array_map('intval', (array) $this->request->getPost('proposals'))));
        if ($ids === []) {
            return;
        }

        try {
            service('taxonomyClient')->attach($ids, $ownerType, $ownerId, auth()->id());
        } catch (\RuntimeException $e) {
            log_message('error', 'Linking suggested terms to {type} {id} failed: {message}', ['type' => $ownerType, 'id' => $ownerId, 'message' => $e->getMessage()]);
        }
    }
}
