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

namespace Eventum\Mail\Exception;

use Eventum\Mail\Helper\MailLoader;
use Exception;
use RuntimeException;
use Zend\Mail;
use Zend\Mail\Header\HeaderInterface;

class InvalidMessageException extends RuntimeException
{
    public static function create(Exception $e, array $params): self
    {
        if (isset($params['headers'])) {
            if (is_string($params['headers'])) {
                MailLoader::convertHeaders($params['headers']);
            }

            // test loading each header to identify which one fails with better error message
            $headers = new Mail\Headers();
            foreach ($params['headers'] as $header) {
                $headerLine = $header instanceof HeaderInterface ? $header->toString() : (string)$header;
                try {
                    $headers->addHeaderLine($headerLine);
                } catch (Mail\Exception\InvalidArgumentException $e) {
                    $message = $e->getMessage() . '; Header: ' . $headerLine;

                    return new self($message, $e->getCode());
                }
            }
        }

        return new self($e->getMessage(), $e->getCode());
    }
}
