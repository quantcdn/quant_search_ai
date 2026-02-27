<?php

namespace Drupal\quantsearch_ai\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\quantsearch_ai\Client\QuantSearchClient;
use Drupal\quantsearch_ai\Service\AuthService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure QuantSearch AI settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The auth service.
   *
   * @var \Drupal\quantsearch_ai\Service\AuthService
   */
  protected $authService;

  /**
   * The QuantSearch client.
   *
   * @var \Drupal\quantsearch_ai\Client\QuantSearchClient
   */
  protected $client;

  /**
   * Constructs the form.
   *
   * @param \Drupal\quantsearch_ai\Service\AuthService $auth_service
   *   The auth service.
   * @param \Drupal\quantsearch_ai\Client\QuantSearchClient $client
   *   The QuantSearch client.
   */
  public function __construct(AuthService $auth_service, QuantSearchClient $client) {
    $this->authService = $auth_service;
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('quantsearch_ai.auth'),
      $container->get('quantsearch_ai.client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'quantsearch_ai_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['quantsearch_ai.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('quantsearch_ai.settings');
    $connection = $this->authService->getConnectionDetails();

    // Connection Section
    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Connection'),
      '#open' => TRUE,
    ];

    if ($connection['connected']) {
      $form['connection']['status'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--status">' .
          $this->t('Connected to QuantSearch. Organization: <strong>@org</strong>', [
            '@org' => $connection['org_name'] ?: $connection['org_id'],
          ]) . '</div>',
      ];

      // Site selector - fetch live from API
      $available_sites = [];
      try {
        $available_sites = $this->client->listSites();
      }
      catch (\Exception $e) {
        // Fall back to cached sites if API fails
        $available_sites = $config->get('available_sites') ?: [];
        $this->messenger()->addWarning($this->t('Could not fetch sites from QuantSearch. Using cached list.'));
      }

      if (count($available_sites) > 0) {
        $site_options = [];
        foreach ($available_sites as $site) {
          $site_options[$site['id']] = $site['name'] . ' (' . ($site['baseUrl'] ?? $site['id']) . ')';
        }

        $form['connection']['site_id'] = [
          '#type' => 'select',
          '#title' => $this->t('Site'),
          '#options' => $site_options,
          '#default_value' => $config->get('site_id'),
          '#description' => $this->t('Select which QuantSearch site to use for this Drupal installation.'),
        ];
      }
      else {
        $form['connection']['no_sites'] = [
          '#type' => 'markup',
          '#markup' => '<div class="messages messages--warning">' .
            $this->t('No sites found. <a href="@url" target="_blank">Create a site</a> in the QuantSearch dashboard first.', [
              '@url' => 'https://quantsearch.ai/dashboard/sites',
            ]) . '</div>',
        ];
      }

      $form['connection']['disconnect'] = [
        '#type' => 'link',
        '#title' => $this->t('Disconnect'),
        '#url' => Url::fromRoute('quantsearch_ai.oauth_disconnect'),
        '#attributes' => [
          'class' => ['button', 'button--danger'],
        ],
      ];
    }
    else {
      $form['connection']['status'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' .
          $this->t('Not connected to QuantSearch. Click the button below to connect your QuantSearch.ai account.') . '</div>',
      ];

      $form['connection']['connect'] = [
        '#type' => 'link',
        '#title' => $this->t('Connect to QuantSearch'),
        '#url' => Url::fromRoute('quantsearch_ai.oauth_connect'),
        '#attributes' => [
          'class' => ['button', 'button--primary'],
        ],
      ];
    }

    // API Settings Section
    $form['api'] = [
      '#type' => 'details',
      '#title' => $this->t('API Settings'),
      '#open' => FALSE,
    ];

    $form['api']['api_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Endpoint'),
      '#default_value' => $config->get('api_endpoint'),
      '#description' => $this->t('QuantSearch API endpoint URL.'),
    ];

    $form['api']['cdn_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Widget CDN URL'),
      '#default_value' => $config->get('cdn_url'),
      '#description' => $this->t('CDN URL for QuantSearch widget scripts.'),
    ];

    // Indexing Section
    $form['indexing'] = [
      '#type' => 'details',
      '#title' => $this->t('Indexing'),
      '#open' => $connection['connected'],
    ];

    $form['indexing']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable content indexing'),
      '#default_value' => $config->get('indexing.enabled'),
      '#description' => $this->t('When enabled, content will be indexed to QuantSearch.'),
    ];

    $form['indexing']['realtime'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Real-time indexing'),
      '#default_value' => $config->get('indexing.realtime'),
      '#description' => $this->t('Index content immediately when saved. If disabled, content will be queued for batch processing.'),
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Get content types
    $content_types = [];
    foreach (\Drupal::entityTypeManager()->getStorage('node_type')->loadMultiple() as $type) {
      $content_types[$type->id()] = $type->label();
    }

    $form['indexing']['content_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types to index'),
      '#options' => $content_types,
      '#default_value' => $config->get('indexing.content_types') ?: [],
      '#description' => $this->t('Select which content types should be indexed to QuantSearch.'),
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['indexing']['exclude_unpublished'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Exclude unpublished content'),
      '#default_value' => $config->get('indexing.exclude_unpublished'),
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['indexing']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch size'),
      '#default_value' => $config->get('indexing.batch_size') ?: 50,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('Number of pages to process in each batch.'),
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Get available view modes for nodes
    $view_modes = \Drupal::service('entity_display.repository')->getViewModes('node');
    $view_mode_options = ['full' => $this->t('Full content')];
    foreach ($view_modes as $id => $view_mode) {
      $view_mode_options[$id] = $view_mode['label'];
    }

    $form['indexing']['view_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('View mode for indexing'),
      '#options' => $view_mode_options,
      '#default_value' => $config->get('indexing.view_mode') ?: 'full',
      '#description' => $this->t('The view mode used to render content for indexing. This captures all fields including Paragraphs, Layout Builder, etc.'),
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Widget Settings
    $form['widgets'] = [
      '#type' => 'details',
      '#title' => $this->t('Widget Settings'),
      '#open' => FALSE,
    ];

    // Chat Widget
    $form['widgets']['chat'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Chat Widget (Global)'),
    ];

    $form['widgets']['chat']['chat_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable chat widget globally'),
      '#default_value' => $config->get('widgets.chat.enabled'),
      '#description' => $this->t('Add the floating chat widget to all pages. You can also use blocks for more control.'),
    ];

    $form['widgets']['chat']['chat_theme'] = [
      '#type' => 'select',
      '#title' => $this->t('Theme'),
      '#options' => [
        'auto' => $this->t('Auto (follows system)'),
        'light' => $this->t('Light'),
        'dark' => $this->t('Dark'),
      ],
      '#default_value' => $config->get('widgets.chat.theme') ?: 'auto',
      '#states' => [
        'visible' => [
          ':input[name="chat_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['widgets']['chat']['chat_position'] = [
      '#type' => 'select',
      '#title' => $this->t('Position'),
      '#options' => [
        'bottom-right' => $this->t('Bottom Right'),
        'bottom-left' => $this->t('Bottom Left'),
      ],
      '#default_value' => $config->get('widgets.chat.position') ?: 'bottom-right',
      '#states' => [
        'visible' => [
          ':input[name="chat_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['widgets']['chat']['chat_color'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Accent Color'),
      '#default_value' => $config->get('widgets.chat.color') ?: '#00d4aa',
      '#description' => $this->t('Hex color code (e.g., #00d4aa)'),
      '#states' => [
        'visible' => [
          ':input[name="chat_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['widgets']['chat']['chat_placeholder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Placeholder'),
      '#default_value' => $config->get('widgets.chat.placeholder') ?: 'Ask a question...',
      '#states' => [
        'visible' => [
          ':input[name="chat_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['widgets']['chat']['chat_greeting'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Greeting Message'),
      '#default_value' => $config->get('widgets.chat.greeting'),
      '#description' => $this->t('Optional greeting shown when chat opens.'),
      '#states' => [
        'visible' => [
          ':input[name="chat_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory()->getEditable('quantsearch_ai.settings');

    // Site selection (update site_name and base_url based on selection)
    $site_id = $form_state->getValue('site_id');
    if ($site_id) {
      $config->set('site_id', $site_id);
      // Fetch fresh site details from API
      try {
        $available_sites = $this->client->listSites();
        foreach ($available_sites as $site) {
          if ($site['id'] === $site_id) {
            $config->set('site_name', $site['name'] ?? '');
            $config->set('base_url', $site['baseUrl'] ?? '');
            break;
          }
        }
      }
      catch (\Exception $e) {
        // If API fails, try to use cached sites
        $available_sites = $config->get('available_sites') ?: [];
        foreach ($available_sites as $site) {
          if ($site['id'] === $site_id) {
            $config->set('site_name', $site['name'] ?? '');
            $config->set('base_url', $site['baseUrl'] ?? '');
            break;
          }
        }
      }
    }

    // API settings
    $config->set('api_endpoint', $form_state->getValue('api_endpoint'));
    $config->set('cdn_url', $form_state->getValue('cdn_url'));

    // Indexing settings
    $config->set('indexing.enabled', (bool) $form_state->getValue('enabled'));
    $config->set('indexing.realtime', (bool) $form_state->getValue('realtime'));
    $config->set('indexing.content_types', array_filter($form_state->getValue('content_types') ?: []));
    $config->set('indexing.exclude_unpublished', (bool) $form_state->getValue('exclude_unpublished'));
    $config->set('indexing.batch_size', (int) $form_state->getValue('batch_size'));
    $config->set('indexing.view_mode', $form_state->getValue('view_mode') ?: 'full');

    // Chat widget settings
    $config->set('widgets.chat.enabled', (bool) $form_state->getValue('chat_enabled'));
    $config->set('widgets.chat.theme', $form_state->getValue('chat_theme'));
    $config->set('widgets.chat.position', $form_state->getValue('chat_position'));
    $config->set('widgets.chat.color', $form_state->getValue('chat_color'));
    $config->set('widgets.chat.placeholder', $form_state->getValue('chat_placeholder'));
    $config->set('widgets.chat.greeting', $form_state->getValue('chat_greeting'));

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
