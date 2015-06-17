<?php
/**
 * This file is part of the DreamFactory Rave(tm)
 *
 * DreamFactory Rave(tm) <http://github.com/dreamfactorysoftware/rave>
 * Copyright 2012-2014 DreamFactory Software, Inc. <support@dreamfactory.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace DreamFactory\Core\Rackspace\Models;

use DreamFactory\Core\Models\FilePublicPath;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Core\SqlDbCore\ColumnSchema;
use DreamFactory\Core\Contracts\ServiceConfigHandlerInterface;

class RackspaceObjectStorageConfig implements ServiceConfigHandlerInterface
{
    /**
     * @param int $id
     *
     * @return array
     */
    public static function getConfig($id)
    {
        $rosConfig = RackspaceConfig::find($id);
        $pathConfig = FilePublicPath::find($id);

        $config = [];

        if (!empty($rosConfig)) {
            $config = $rosConfig->toArray();
        }

        if (!empty($pathConfig)) {
            $config = array_merge($config, $pathConfig->toArray());
        }

        return $config;
    }

    /**
     * {@inheritdoc}
     */
    public static function validateConfig($config)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public static function setConfig($id, $config)
    {
        $rosConfig = RackspaceConfig::find($id);
        $pathConfig = FilePublicPath::find($id);
        $configPath = [
            'public_path' => ArrayUtils::get($config, 'public_path')
        ];
        $configRos = [
            'service_id'   => ArrayUtils::get($config, 'service_id'),
            'username'     => ArrayUtils::get($config, 'username'),
            'password'     => ArrayUtils::get($config, 'password'),
            'tenant_name'  => ArrayUtils::get($config, 'tenant_name'),
            'api_key'      => ArrayUtils::get($config, 'api_key'),
            'url'          => ArrayUtils::get($config, 'url'),
            'region'       => ArrayUtils::get($config, 'region'),
            'storage_type' => ArrayUtils::get($config, 'storage_type')
        ];

        ArrayUtils::removeNull($configRos);
        ArrayUtils::removeNull($configPath);

        if (!empty($rosConfig)) {
            $rosConfig->update($configRos);
        } else {
            //Making sure service_id is the first item in the config.
            //This way service_id will be set first and is available
            //for use right away. This helps setting an auto-generated
            //field that may depend on parent data. See OAuthConfig->setAttribute.
            $configRos = array_reverse($configRos, true);
            $configRos['service_id'] = $id;
            $configRos = array_reverse($configRos, true);
            RackspaceConfig::create($configRos);
        }

        if (!empty($pathConfig)) {
            $pathConfig->update($configPath);
        } else {
            //Making sure service_id is the first item in the config.
            //This way service_id will be set first and is available
            //for use right away. This helps setting an auto-generated
            //field that may depend on parent data. See OAuthConfig->setAttribute.
            $configPath = array_reverse($configPath, true);
            $configPath['service_id'] = $id;
            $configPath = array_reverse($configPath, true);
            FilePublicPath::create($configPath);
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function removeConfig($id)
    {
        // deleting is not necessary here due to cascading on_delete relationship in database
    }

    /**
     * {@inheritdoc}
     */
    public static function getAvailableConfigs()
    {
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public static function getConfigSchema()
    {
        $rosConfig = new RackspaceConfig();
        $pathConfig = new FilePublicPath();
        $out = [];

        $rosSchema = $rosConfig->getTableSchema();
        if ($rosSchema) {
            foreach ($rosSchema->columns as $name => $column) {
                if ('service_id' === $name) {
                    continue;
                }

                /** @var ColumnSchema $column */
                $out[$name] = $column->toArray();
            }
            //return $out;
        }

        $pathSchema = $pathConfig->getTableSchema();
        if ($pathSchema) {
            foreach ($pathSchema->columns as $name => $column) {
                if ('service_id' === $name) {
                    continue;
                }

                /** @var ColumnSchema $column */
                $out[$name] = $column->toArray();
            }
        }

        if (!empty($out)) {
            return $out;
        }

        return null;
    }
}