<?php

declare(strict_types=1);

namespace Drupal\elaintehtaat\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\elaintehtaat\Album\OneAlbumPerMedia;
use Drupal\node\NodeInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the OneAlbumPerMedia constraint.
 */
final class OneAlbumPerMediaConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    private readonly OneAlbumPerMedia $rule,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof OneAlbumPerMediaConstraint);
    if (!$value instanceof FieldItemListInterface) {
      return;
    }
    $album = $value->getEntity();
    if (!$album instanceof NodeInterface) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $original = $album->isNew() ? NULL : $storage->loadUnchanged($album->id());
    $violations = $this->rule->violations($album, $original instanceof NodeInterface ? $original : NULL);
    if ($violations === []) {
      return;
    }

    $media = $this->entityTypeManager->getStorage('media')->loadMultiple(array_keys($violations));
    $albums = $storage->loadMultiple(array_filter($violations));
    foreach ($violations as $media_id => $album_id) {
      $label = isset($media[$media_id]) ? (string) $media[$media_id]->label() : '#' . $media_id;
      if ($album_id === NULL) {
        $this->context->addViolation($constraint->repeatedMessage, ['%media' => $label]);
        continue;
      }
      $this->context->addViolation($constraint->inOtherAlbumMessage, [
        '%media' => $label,
        '%album' => isset($albums[$album_id]) ? (string) $albums[$album_id]->label() : '#' . $album_id,
      ]);
    }
  }

}
