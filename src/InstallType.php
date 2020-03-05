<?php
namespace Maestro;

use MyCLabs\Enum\Enum;

/**
 * @method static self BRANCH()
 * @method static self RELEASE()
 */
class InstallType extends Enum
{
    private const BRANCH = 'branch';
    private const RELEASE = 'release';
}