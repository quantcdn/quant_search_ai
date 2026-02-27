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

    return $form;
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
