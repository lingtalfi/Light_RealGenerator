<?php


namespace Ling\Light_RealGenerator\Generator;


use Ling\ArrayVariableResolver\ArrayVariableResolverUtil;
use Ling\BabyYaml\BabyYamlUtil;
use Ling\Bat\FileSystemTool;
use Ling\Light_DatabaseInfo\Service\LightDatabaseInfoService;
use Ling\Light_RealGenerator\Exception\LightRealGeneratorException;
use Ling\Light_RealGenerator\Util\RepresentativeColumnFinderUtil;

/**
 * The FormConfigGenerator class.
 */
class FormConfigGenerator extends BaseConfigGenerator
{


    /**
     * Generates the list configuration files according to the given @page(configuration block).
     * @param array $config
     * @throws \Exception
     */
    public function generate(array $config)
    {
        $this->setConfig($config);
        $tables = $this->getTables();

        $appDir = $this->container->getApplicationDir();
        $targetDir = $this->getKeyValue("form.target_dir");
        $targetDir = str_replace('{app_dir}', $appDir, $targetDir);

        foreach ($tables as $table) {
            $content = $this->getFileContent($table);
            $fileName = $table . ".byml";
            $path = $targetDir . '/' . $fileName;
            FileSystemTool::mkfile($path, $content);
        }


        $this->generateContentByTables($tables);
    }



    //--------------------------------------------
    //
    //--------------------------------------------
    /**
     * Returns the content of the config file for the given table.
     *
     * @param string $table
     * @return string
     * @throws \Exception
     */
    protected function getFileContent(string $table): string
    {
        $arr = [];


        $pluginName = $this->getKeyValue('plugin_name');
        $database = $this->getKeyValue('database_name', false, null);
        $formHandlerClassGeneral = $this->getKeyValue("form.form_handler_class_general", false, null);
        $formHandlerClassSpecific = $this->getKeyValue("form.form_handler_class_specific.$table", false, null);
        $globalIgnoreColumns = $this->getKeyValue("ignore_columns.$table", false, []);
        $ignoreColumns = $this->getKeyValue("form.ignore_columns.$table", false, []);
        $customFields = $this->getKeyValue("form.fields.$table", false, []);
        $notRequiredCols = $this->getKeyValue("form.not_required.$table", false, []);
        $customVariables = $this->getKeyValue("form.variables", false, []);
        $fieldsMergeSpecific = $this->getKeyValue("form.fields_merge_specific.$table", false, []);
        $onSuccessHandler = $this->getKeyValue("form.on_success_handler", false, []);
        $formTitle = $this->getKeyValue("form.title", false, "{Label} form");
        $specialFields = $this->getKeyValue("form.special_fields", false, []);
        $onSuccessHandlerType = $onSuccessHandler['type'] ?? "database";


        // special types
        $chloroformExtensions = $specialFields['chloroform_extensions'] ?? [];
        $useTableList = $chloroformExtensions['use_table_list'] ?? true;
        $tableListConfigFile = $chloroformExtensions['table_list_config_file'] ?? null;


        $genericTags = $this->getGenericTagsByTable($table);
        $formTitle = str_replace(array_keys($genericTags), array_values($genericTags), $formTitle);
        $arr['title'] = $formTitle;

        $theVariables = $customVariables;
        $theVariables['table'] = $table;
        $theVariables['field'] = "";

        $variableResolver = new ArrayVariableResolverUtil();
        $variableResolver->setFirstSymbol('');


        $ignoreColumns = array_unique(array_merge($globalIgnoreColumns, $ignoreColumns));
        /**
         * @var $dbInfo LightDatabaseInfoService
         */
        $dbInfo = $this->container->get('database_info');
        $tableInfo = $dbInfo->getTableInfo($table, $database);
        $foreignKeysInfo = $tableInfo['foreignKeysInfo'];
        $autoIncrementedKey = $tableInfo['autoIncrementedKey'];
        if (false !== $autoIncrementedKey) {
            $ignoreColumns[] = $autoIncrementedKey;
        }

        $main['ric'] = $tableInfo['ric'];
        $types = $tableInfo['types'];
        $columns = array_merge(array_diff($tableInfo['columns'], $ignoreColumns));

        //--------------------------------------------
        // FORM HANDLER
        //--------------------------------------------
        $formHandler = [];
        if (null !== $formHandlerClassSpecific) {
            $formHandler['class'] = $formHandlerClassSpecific;
        } elseif (null !== $formHandlerClassGeneral) {
            $formHandler['class'] = $formHandlerClassGeneral;
        }

        $formId = "realgen-$table";
        $formHandler['id'] = $formId;

        $fields = [];
        foreach ($columns as $col) {

            if (array_key_exists($col, $types)) {


                $theVariables['field'] = $col;

                $customItem = $customFields[$col] ?? [];
                $merge = [];
                if (array_key_exists($col, $fieldsMergeSpecific)) {
                    $mergeArr = $fieldsMergeSpecific[$col];
                    if (is_string($mergeArr) && '$' === substr($mergeArr, 0, 1)) {
                        $alias = substr($mergeArr, 1);
                        $merge = $this->getKeyValue("form.fields_merge_aliases.$alias");
                    } else {
                        // assuming it's an array
                        $merge = $mergeArr;
                    }
                }


                // special item?
                $specialItem = [];
                if (true === $useTableList && array_key_exists($col, $foreignKeysInfo)) {
                    list($rfDb, $rfTable, $rfCol) = $foreignKeysInfo[$col];
                    $specialItem = [
                        "type" => "table_list",
                        "tableListIdentifier" => $pluginName . ".$rfTable.$rfCol",
//                        "threshold" => 200,
                    ];
                    if (null !== $tableListConfigFile) {

                    }
                }


                $sqlType = $types[$col];
                $type = $this->getFieldType($sqlType);
                $label = str_replace('_', ' ', ucfirst(strtolower($col)));


                $validators = [];
                if (false === in_array($col, $notRequiredCols, true)) {
                    $validators["required"] = [];
                }

                $fieldItem = [
                    'label' => $label,
                    'type' => $type,
                    'validators' => $validators,
                ];

                // note: merge is less specific than custom item
                $fieldItem = array_replace_recursive($fieldItem, $specialItem, $merge, $customItem);

                $variableResolver->resolve($fieldItem, $theVariables);


                $fields[$col] = $fieldItem;


            } else {
                throw new LightRealGeneratorException("Unknoqn column type for column $col, table $table.");
            }
        }
        $formHandler['fields'] = $fields;


        $arr['form_handler'] = $formHandler;


        //--------------------------------------------
        // ON SUCCESS HANDLER
        //--------------------------------------------
        $onSuccessHandlerArr = [];
        switch ($onSuccessHandlerType) {
            case "database":
                $onSuccessHandlerArr = [
                    "type" => "database",
                    "params" => [
                        "table" => $table,
                        "pluginName" => $pluginName
                    ],
                ];
                break;
            default:
                throw new LightRealGeneratorException("Unknown success handler type: $onSuccessHandlerType.");
                break;
        }
        $arr['on_success_handler'] = $onSuccessHandlerArr;

        return BabyYamlUtil::getBabyYamlString($arr);
    }


    /**
     * Returns the field type for the given sql type.
     * For the returned field types, the chloroform list can be found here:
     * https://github.com/lingtalfi/Light_Realform/blob/master/doc/pages/realform-config-example.md
     *
     *
     * @param string $type
     * @return string
     * @throws \Exception
     */
    protected function getFieldType(string $type): string
    {


        $p = explode('(', $type, 2);
        $simpleType = trim(array_shift($p));
        switch ($simpleType) {
            case "tinyint":
            case "smallint":
            case "mediumint":
            case "int":
            case "integer":
            case "bigint":
                $type = 'number';
                break;
            case "date":
                $type = 'date';
                break;
            case "datetime":
            case "timestamp":
                $type = 'datetime';
                break;
            case "bit":
            case "bool":
            case "boolean":
                $type = 'select';
                break;
            case "time":
                $type = 'time';
                break;
            case "decimal":
            case "float":
            case "double":
            default:
                $type = 'string';
                break;
        }


        return $type;
    }


    /**
     * Generate some content that applies to the whole table selection rather than on each individual tables.
     *
     * @param array $tables
     * @throws \Exception
     */
    protected function generateContentByTables(array $tables)
    {

        $pluginName = $this->getKeyValue('plugin_name');
        $specialFields = $this->getKeyValue("form.special_fields", false, []);
        $chloroformExtensions = $specialFields['chloroform_extensions'] ?? [];
        $useTableList = $chloroformExtensions['use_table_list'] ?? true;
        $tableListConfigFile = $chloroformExtensions['table_list_config_file'] ?? null;
        $database = $this->getKeyValue('database_name', false, null);
        $commonRepresentativeMatches = $this->getKeyValue("list.common_representative_matches", false, [
            'name',
            'label',
            'identifier',
        ]);


        $reprFinder = new RepresentativeColumnFinderUtil();
        $reprFinder->setContainer($this->container);
        $reprFinder->setCommonMatches($commonRepresentativeMatches);

        /**
         * @var $dbInfo LightDatabaseInfoService
         */
        $dbInfo = $this->container->get('database_info');


        if (true === $useTableList && null !== $tableListConfigFile) {
            $appDir = $this->container->getApplicationDir();
            $tableListConfigFile = str_replace('{app_dir}', $appDir, $tableListConfigFile);


            $arr = [];
            foreach ($tables as $table) {
                $tableInfo = $dbInfo->getTableInfo($table, $database);
                $columns = $tableInfo['columns'];
                $foreignKeysInfo = $tableInfo['foreignKeysInfo'];
                foreach ($columns as $col) {
                    if (array_key_exists($col, $foreignKeysInfo)) {
                        list($rfDb, $rfTable, $rfCol) = $foreignKeysInfo[$col];
                        $commonRepresentative = $reprFinder->findRepresentativeColumn($rfTable);
                        $key = "$rfTable.$rfCol";
                        $arr[$key] = [
                            "fields" => "$rfCol as value, concat($rfCol, '. ', $commonRepresentative) as label",
                            "table" => $rfTable,
                            "column" => $rfCol,
                            "csrf_token" => true,
                            "micro_permission" => "$pluginName.tables.$rfTable.read",
                        ];
                    }
                }

            }
            FileSystemTool::mkfile($tableListConfigFile, BabyYamlUtil::getBabyYamlString($arr));
        }
    }
}