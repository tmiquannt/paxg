<?php
declare(strict_types=1);

namespace MiniERP\Domain;

use DomainException;

/** A valid command could not be applied because the persisted business state changed. */
final class BusinessConflictException extends DomainException
{
}
