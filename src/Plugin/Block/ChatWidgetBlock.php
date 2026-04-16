<?php

namespace Drupal\quantsearch_ai\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a QuantSearch Chat Widget block.
 *
 * @Block(
 *   id = "quantsearch_chat_widget",
 *   admin_label = @Translation("QuantSearch Chat Widget"),
 *   category = @Translation("QuantSearch")
 * )
 */
class ChatWidgetBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'theme' => 'auto',
      'position' => 'bottom-right',
      'color' => '#00d4aa',
      'placeholder' => 'Ask a question...',
      'greeting' => '',
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

    $form['position'] = [
      '#type' => 'select',
      '#title' => $this->t('Position'),
      '#options' => [
        'bottom-right' => $this->t('Bottom Right'),
        'bottom-left' => $this->t('Bottom Left'),
      ],
      '#default_value' => $this->configuration['position'],
    ];

    $form['color'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Accent Color'),
      '#default_value' => $this->configuration['color'],
      '#description' => $this->t('Hex color code (e.g., #00d4aa)'),
    ];

    $form['placeholder'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Placeholder Text'),
      '#default_value' => $this->configuration['placeholder'],
    ];

    $form['greeting'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Greeting Message'),
      '#default_value' => $this->configuration['greeting'],
      '#description' => $this->t('Optional greeting shown when chat opens.'),
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
    $this->configuration['position'] = $form_state->getValue('position');
    $this->configuration['color'] = $form_state->getValue('color');
    $this->configuration['placeholder'] = $form_state->getValue('placeholder');
    $this->configuration['greeting'] = $form_state->getValue('greeting');
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

    $attributes = [
      'src' => $cdn_url . '/widget.js',
      'data-site-id' => $site_id,
      'data-theme' => $this->configuration['theme'],
      'data-position' => $this->configuration['position'],
      'data-color' => $this->configuration['color'],
      'data-placeholder' => $this->configuration['placeholder'],
      'async' => 'async',
    ];

    if (!empty($this->configuration['greeting'])) {
      $attributes['data-greeting'] = $this->configuration['greeting'];
    }

    if (!empty($this->configuration['preset_filters'])) {
      $attributes['data-filters'] = $this->configuration['preset_filters'];
    }

    return [
      '#type' => 'html_tag',
      '#tag' => 'script',
      '#attributes' => $attributes,
      '#cache' => [
        'tags' => ['config:quantsearch_ai.settings'],
      ],
    ];
  }

}
