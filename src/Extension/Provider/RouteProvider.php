<?php

/*
 * This file is part of the Eventum (Issue Tracking System) package.
 *
 * @copyright (c) Eventum Team
 * @license GNU General Public License, version 2 or later (GPL-2+)
 *
 * For the full copyright and license information,
 * please see the COPYING and AUTHORS files
 * that were distributed with this source code.
 */

namespace Eventum\Extension\Provider;

use Symfony\Component\Routing\RouteCollectionBuilder;

interface RouteProvider extends ExtensionProvider
{
    /**
     * @since 3.8.1
     */
    public function configureRoutes(RouteCollectionBuilder $routes): void;
}
