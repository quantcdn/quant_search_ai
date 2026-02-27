<?php

namespace Drupal\quantsearch_ai\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for OAuth-to-API-Key authentication flow.
 */
class OAuthController extends ControllerBase {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The key repository.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs the OAuthController.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ClientInterface $http_client, KeyRepositoryInterface $key_repository, ConfigFactoryInterface $config_factory) {
    $this->httpClient = $http_client;
    $this->keyRepository = $key_repository;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client'),
      $container->get('key.repository'),
      $container->get('config.factory')
    );
  }

  /**
   * Initiates the OAuth flow by redirecting to QuantSearch.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Drupal\Core\Routing\TrustedRedirectResponse
   *   A redirect response to QuantSearch OAuth.
   */
  public function connect(Request $request) {
    // Generate CSRF state token
    $state = bin2hex(random_bytes(16));

    // Store state in session for validation on callback
    $session = $request->getSession();
    $session->set('quantsearch_oauth_state', $state);

    // Build callback URL
    $callback_url = Url::fromRoute('quantsearch_ai.oauth_callback', [], [
      'absolute' => TRUE,
    ])->toString();

    // Get API endpoint from config
    $config = $this->configFactory->get('quantsearch_ai.settings');
    $api_endpoint = $config->get('api_endpoint') ?: 'https://quantsearch.ai/api';

    // Build authorization URL
    $auth_url = $api_endpoint . '/auth/oauth/authorize?' . http_build_query([
      'client_id' => 'drupal-quantsearch',
      'redirect_uri' => $callback_url,
      'state' => $state,
      'response_type' => 'code',
    ]);

    return new TrustedRedirectResponse($auth_url);
  }

  /**
   * Handles the OAuth callback from QuantSearch.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the settings form.
   */
  public function callback(Request $request) {
    $code = $request->query->get('code');
    $state = $request->query->get('state');
    $error = $request->query->get('error');
    $error_description = $request->query->get('error_description');

    // Validate state (CSRF protection)
    $session = $request->getSession();
    $stored_state = $session->get('quantsearch_oauth_state');
    $session->remove('quantsearch_oauth_state');

    if (!$stored_state || $stored_state !== $state) {
      $this->messenger()->addError($this->t('Invalid OAuth state. Please try again.'));
      return $this->redirect('quantsearch_ai.settings_form');
    }

    // Check for OAuth error
    if ($error) {
      $message = $error_description ?: $error;
      $this->messenger()->addError($this->t('OAuth failed: @message', ['@message' => $message]));
      return $this->redirect('quantsearch_ai.settings_form');
    }

    // Check for authorization code
    if (!$code) {
      $this->messenger()->addError($this->t('No authorization code received.'));
      return $this->redirect('quantsearch_ai.settings_form');
    }

    // Exchange code for API key
    try {
      $callback_url = Url::fromRoute('quantsearch_ai.oauth_callback', [], [
        'absolute' => TRUE,
      ])->toString();

      $config = $this->configFactory->get('quantsearch_ai.settings');
      $api_endpoint = $config->get('api_endpoint') ?: 'https://quantsearch.ai/api';

      $response = $this->httpClient->post($api_endpoint . '/auth/oauth/token', [
        'form_params' => [
          'grant_type' => 'authorization_code',
          'code' => $code,
          'redirect_uri' => $callback_url,
          'client_id' => 'drupal-quantsearch',
        ],
        'headers' => [
          'Accept' => 'application/json',
        ],
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);

      if (empty($data['api_key'])) {
        throw new \Exception('No API key in response');
      }

      // Store API key in Key module
      $key_id = 'quantsearch_api_key';
      $key_storage = $this->entityTypeManager()->getStorage('key');

      // Delete existing key if present
      $existing_key = $key_storage->load($key_id);
      if ($existing_key) {
        $existing_key->delete();
      }

      // Create new key entity
      $key = $key_storage->create([
        'id' => $key_id,
        'label' => 'QuantSearch API Key',
        'description' => 'Auto-generated API key for QuantSearch integration',
        'key_type' => 'authentication',
        'key_provider' => 'config',
        'key_provider_settings' => [
          'key_value' => $data['api_key'],
        ],
      ]);
      $key->save();

      // Update module configuration
      $editable_config = $this->configFactory->getEditable('quantsearch_ai.settings');
      $editable_config->set('api_key_id', $key_id);
      $editable_config->set('org_id', $data['org_id'] ?? '');
      $editable_config->set('org_name', $data['org_name'] ?? '');

      // Store all available sites for selection
      if (!empty($data['sites']) && is_array($data['sites'])) {
        $editable_config->set('available_sites', $data['sites']);
        // Set first site as default
        $site = $data['sites'][0];
        $editable_config->set('site_id', $site['id'] ?? '');
        $editable_config->set('site_name', $site['name'] ?? '');
        $editable_config->set('base_url', $site['baseUrl'] ?? '');
      }

      $editable_config->save();

      $this->messenger()->addStatus($this->t('Successfully connected to QuantSearch! Organization: @org', [
        '@org' => $data['org_name'] ?? $data['org_id'],
      ]));

    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to connect to QuantSearch: @message', [
        '@message' => $e->getMessage(),
      ]));
      $this->getLogger('quantsearch_ai')->error('OAuth token exchange failed: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $this->redirect('quantsearch_ai.settings_form');
  }

  /**
   * Disconnects from QuantSearch by removing stored credentials.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the settings form.
   */
  public function disconnect() {
    $config = $this->configFactory->get('quantsearch_ai.settings');
    $key_id = $config->get('api_key_id');

    // Delete the stored key
    if ($key_id) {
      $key = $this->keyRepository->getKey($key_id);
      if ($key) {
        $key->delete();
      }
    }

    // Clear configuration
    $editable_config = $this->configFactory->getEditable('quantsearch_ai.settings');
    $editable_config->set('api_key_id', '');
    $editable_config->set('org_id', '');
    $editable_config->set('org_name', '');
    $editable_config->set('site_id', '');
    $editable_config->set('site_name', '');
    $editable_config->set('base_url', '');
    $editable_config->save();

    $this->messenger()->addStatus($this->t('Disconnected from QuantSearch.'));

    return $this->redirect('quantsearch_ai.settings_form');
  }

}
