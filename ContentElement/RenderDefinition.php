<?php

/*
 * This file is part of the BartacusBundle.
 *
 * The BartacusBundle is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * The BartacusBundle is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with the BartacusBundle. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Bartacus\Bundle\BartacusBundle\ContentElement;

/**
 * @author Patrik Karisch <p.karisch@pixelart.at>
 */
class RenderDefinition
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var bool
     */
    private $cached;

    /**
     * @var string
     */
    private $controller;

    /**
     * @param string $name
     * @param bool   $cached
     * @param string $controller
     */
    public function __construct($name, $cached, $controller)
    {
        $this->name = $name;
        $this->cached = $cached;
        $this->controller = $controller;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return bool
     */
    public function isCached(): bool
    {
        return $this->cached;
    }

    /**
     * @return string
     */
    public function getController(): string
    {
        return $this->controller;
    }
}
