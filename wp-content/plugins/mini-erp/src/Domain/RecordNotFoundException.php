<?php
declare(strict_types=1);

namespace MiniERP\Domain;

use DomainException;

/** The requested record is absent or outside the actor's record scope. */
final class RecordNotFoundException extends DomainException
{
}
