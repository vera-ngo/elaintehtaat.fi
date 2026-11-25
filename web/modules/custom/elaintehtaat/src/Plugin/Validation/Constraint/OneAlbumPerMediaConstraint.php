<?php

declare(strict_types=1);

namespace Drupal\elaintehtaat\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Attribute\HasNamedArguments;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * An album may only take on media that no other album holds.
 *
 * Added to the album media field by OneAlbumPerMediaHooks.
 *
 * @see \Drupal\elaintehtaat\Album\OneAlbumPerMedia
 */
#[Constraint(
  id: self::PLUGIN_ID,
  label: new TranslatableMarkup('One album per media item', [], ['context' => 'Validation']),
)]
final class OneAlbumPerMediaConstraint extends SymfonyConstraint {

  /**
   * The plugin ID.
   */
  public const string PLUGIN_ID = 'OneAlbumPerMedia';

  #[HasNamedArguments]
  public function __construct(
    mixed $options = NULL,
    public string $inOtherAlbumMessage = '%media is already in the album %album. Remove it from that album first.',
    public string $repeatedMessage = '%media is in this album more than once.',
    ?array $groups = NULL,
    mixed $payload = NULL,
  ) {
    parent::__construct($options, $groups, $payload);
  }

}
