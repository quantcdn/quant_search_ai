<?php

namespace Drupal\quantsearch_ai\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a QuantSearch full Search Page block.
 *
 * @Block(
 *   id = "quantsearch_search_page",
 *   admin_label = @Translation("QuantSearch Search Page"),
 *   category = @Translation("QuantSearch")
 * )
 */
class SearchPageBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'theme' => 'auto',
      'color' => '#10b981',
      'show_ai_answer' => TRUE,
      'enable_facets' => FALSE,
      'facet_position' => 'left',
      'preset_filters' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form['theme'] = [
      '#type' => 'select',
      '#title' => $this->t('Theme'),
      '#options' => [
        'auto' => $this->t('Auto (follows system)'),
        'light' => $this->t('Light'),
        'dark' => $this->t('Dark'),
      ],
      '#default_value' => $this->configuration['theme'],
    ];

    $form['color'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Accent Color'),
      '#default_value' => $this->configuration['color'],
      '#description' => $this->t('Hex color code (e.g., #10b981)'),
    ];

    $form['show_ai_answer'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show AI answer'),
      '#default_value' => $this->configuration['show_ai_answer'],
      '#description' => $this->t('Display an AI-generated answer above search results.'),
    ];

    $form['enable_facets'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable faceted search'),
      '#description' => $this->t('Show filter controls based on the site metadata schema.'),
      '#default_value' => $this->configuration['enable_facets'],
    ];

    $form['facet_position'] = [
      '#type' => 'select',
      '#title' => $this->t('Facet position'),
      '#options' => [
        'left' => $this->t('Left sidebar'),
        'top' => $this->t('Above results'),
      ],
      '#default_value' => $this->configuration['facet_position'],
      '#states' => [
        'visible' => [
          ':input[name="settings[enable_facets]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['preset_filters'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Pre-set filters (JSON)'),
      '#description' => $this->t('Optional JSON filters applied to all searches. Example: {"content_type":"policy"}'),
      '#default_value' => $this->configuration['preset_filters'],
      '#rows' => 3,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockValidate($form, FormStateInterface $form_state) {
    $filters = $form_state->getValue('preset_filters');
    if (!empty($filters)) {
      json_decode($filters);
      if (json_last_error() !== JSON_ERROR_NONE) {
        $form_state->setErrorByName('preset_filters', $this->t('Pre-set filters must be valid JSON. Error: @error', [
          '@error' => json_last_error_msg(),
        ]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['theme'] = $form_state->getValue('theme');
    $this->configuration['color'] = $form_state->getValue('color');
    $this->configuration['show_ai_answer'] = $form_state->getValue('show_ai_answer');
    $this->configuration['enable_facets'] = $form_state->getValue('enable_facets');
    $this->configuration['facet_position'] = $form_state->getValue('facet_position');
    $this->configuration['preset_filters'] = $form_state->getValue('preset_filters');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = \Drupal::config('quantsearch_ai.settings');
    $site_id = $config->get('site_id');
    $cdn_url = $config->get('cdn_url') ?: 'https://cdn.quantsearch.ai/v1';

    if (!$site_id) {
      return [
        '#markup' => $this->t('QuantSearch not configured.'),
        '#cache' => ['max-age' => 0],
      ];
    }

    return [
      '#theme' => 'quantsearch_search_page',
      '#site_id' => $site_id,
      '#cdn_url' => $cdn_url,
      '#theme_setting' => $this->configuration['theme'],
      '#color' => $this->configuration['color'],
      '#show_ai_answer' => $this->configuration['show_ai_answer'],
      '#enable_facets' => $this->configuration['enable_facets'],
      '#facet_position' => $this->configuration['facet_position'],
      '#preset_filters' => $this->configuration['preset_filters'],
      '#cache' => [
        'tags' => ['config:quantsearch_ai.settings'],
      ],
    ];
  }

}
