<?php

namespace Drupal\islandora_binary_object\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Upload form when ingesting binary objects.
 */
class IslandoraBinaryObjectUploadForm extends FormBase {

  protected $fileEntityStorage;

  /**
   * Constructor.
   */
  public function __construct(EntityStorageInterface $file_entity_storage) {
    $this->fileEntityStorage = $file_entity_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')->getStorage('file')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'islandora_binary_object_upload_form';
  }

  /**
   * Defines a file upload form for uploading the file for storage.
   *
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $upload_size = min((int) ini_get('post_max_size'), (int) ini_get('upload_max_filesize'));
    $thumbnail_extensions = ['gif jpg png jpeg'];

    return [
      'file' => [
        '#title' => $this->t('File'),
        '#type' => 'managed_file',
        '#required' => $this->config('islandora.settings')->get('islandora_require_obj_upload'),
        '#description' => $this->t('Select a file to upload.<br/>Files must be less than <strong>@size MB.</strong>', ['@size' => $upload_size]),
        '#default_value' => $form_state->getValue('file') ? $form_state->getValue('file') : NULL,
        '#upload_location' => 'temporary://',
        '#upload_validators' => [
          // Assume its specified in MB.
          'file_validate_extensions' => [],
          'file_validate_size' => [$upload_size * 1024 * 1024],
        ],
      ],
      'supply_thumbnail' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Upload Thumbnail'),
      ],
      'thumbnail_section' => [
        'thumbnail_file' => [
          '#title' => $this->t('Thumbnail File'),
          '#type' => 'managed_file',
          '#description' => $this->t('Select a file to upload.<br/>Files must be less than <strong>@size MB.</strong><br/>Allowed file types: <strong>@ext.</strong>', ['@size' => $upload_size, '@ext' => $thumbnail_extensions[0]]),
          '#default_value' => $form_state->getValue('thumbnail_file') ? $form_state->getValue('thumbnail_file') : NULL,
          '#upload_location' => 'temporary://',
          '#upload_validators' => [
            'file_validate_extensions' => $thumbnail_extensions,
            // Assume its specified in MB.
            'file_validate_size' => [$upload_size * 1024 * 1024],
          ],
        ],
        'scale_thumbnail' => [
          '#type' => 'checkbox',
          '#title' => $this->t('Scale Thumbnail'),
          '#attributes' => ['checked' => 'checked'],
        ],
        '#type' => 'item',
        '#states' => [
          'visible' => ['#edit-supply-thumbnail' => ['checked' => TRUE]],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getValue('supply_thumbnail') &&
      !$form_state->getValue('thumbnail_file')) {
      $form_state->setErrorByName('thumbnail_file', $this->t('If you select "Upload Thumbnail" please supply a file.'));
    }
  }

  /**
   * Adds the uploaded file into the ingestable objects 'OBJ' datastream.
   *
   * May also populate the TN datastream.
   *
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $form_state->loadInclude('islandora', 'inc', 'includes/utilities');
    $object = islandora_ingest_form_get_object($form_state);

    if ($form_state->getValue('file')) {
      $file = $this->fileEntityStorage->load(reset($form_state->getValue('file')));
      $datastream = isset($object['OBJ']) ?
        $object['OBJ'] :
        $datastream = $object->constructDatastream('OBJ', 'M');

      $datastream->setContentFromFile($file->getFileUri(), FALSE);

      if ($datastream->label != $file->getFilename()) {
        $datastream->label = $file->getFilename();
      }

      if ($datastream->mimetype != $file->getMimeType()) {
        $datastream->mimetype = $file->getMimeType();
      }

      if (!isset($object['OBJ'])) {
        $object->ingestDatastream($datastream);
      }
    }

    if ($form_state->getValue('supply_thumbnail')) {
      $thumbnail_file = $this->fileEntityStorage->load(reset($form_state->getValue('thumbnail_file')));

      if ($form_state->getValue('scale_thumbnail')) {
        islandora_scale_thumbnail($thumbnail_file, 200, 200);
      }

      if (empty($object['TN'])) {
        $tn = $object->constructDatastream('TN', 'M');
        $object->ingestDatastream($tn);
      }
      else {
        $tn = $object['TN'];
      }
      $tn->setContentFromFile($thumbnail_file->getFileUri(), FALSE);

      if ($tn->label != $thumbnail_file->getFilename()) {
        $tn->label = $thumbnail_file->getFilename();
      }

      if ($tn->mimetype != $thumbnail_file->getMimeType()) {
        $tn->mimetype = $thumbnail_file->getMimeType();
      }
    }
  }

}
