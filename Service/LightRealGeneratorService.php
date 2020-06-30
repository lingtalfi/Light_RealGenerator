<?php


namespace Ling\Light_RealGenerator\Service;


use Ling\BabyYaml\BabyYamlUtil;
use Ling\Light\ServiceContainer\LightServiceContainerInterface;
use Ling\Light_Logger\LightLoggerService;
use Ling\Light_RealGenerator\Exception\LightRealGeneratorException;
use Ling\Light_RealGenerator\Generator\FormConfigGenerator;
use Ling\Light_RealGenerator\Generator\ListConfigGenerator;

/**
 * The LightRealGeneratorService class.
 */
class LightRealGeneratorService
{


    /**
     * This property holds the container for this instance.
     * @var LightServiceContainerInterface
     */
    protected $container;

    /**
     * This property holds the options for this instance.
     *
     * Available options are:
     *
     * - useDebug: bool = false.
     *      Whether to log debug messages to the logs.
     *      If true, the debug messages are sent via the channel specified with the debugLogChannel option.
     *
     * - debugLogChannel: string=real_generator.debug, the channel used to write the log messages.
     *
     *
     *
     *
     *
     * @var array
     */
    protected $options;


    /**
     * Builds the LightRealGeneratorService instance.
     */
    public function __construct()
    {
        $this->container = null;
        $this->options = [];
    }


    /**
     * Generates the configuration files for both the @page(realist) and @page(realform) plugins,
     * according to the @page(configuration block) identified by the given file and identifier.
     *
     * The default identifier defaults to "main".
     *
     *
     * @param string $file
     * @param string|null $identifier
     * @throws \Exception
     */
    public function generate(string $file, string $identifier = null)
    {
        $conf = BabyYamlUtil::readFile($file);
        if (null === $identifier) {
            $identifier = 'main';
        }


        $this->debugLog("--clean--"); // reinitializing the log file
        $this->debugLog("Launching real_generator with identifier=\"$identifier\" and file=\"$file\".");




        if (array_key_exists($identifier, $conf)) {
            $genConf = $conf[$identifier];


            // replacing variables now
            $variables = $genConf['variables'] ?? [];
            array_walk_recursive($genConf, function (&$v) use (&$n, $variables) {
                foreach ($variables as $variable => $value) {
                    if (false !== strpos($v, '{$' . $variable . '}')) {
                        $v = str_replace('{$' . $variable . '}', $value, $v);
                    }
                }
            });


            $debugCallable = [$this, "debugLog"];


            if (array_key_exists("list", $genConf)) {
                $this->debugLog("List configuration found.");
                $listGenerator = new ListConfigGenerator();
                $listGenerator->setDebugCallable($debugCallable);
                $listGenerator->setContainer($this->container);
                $listGenerator->generate($genConf);
            } else {
                $this->debugLog("No list configuration found.");
            }


            if (array_key_exists("form", $genConf)) {
                $this->debugLog("Form configuration found.");
                $formGenerator = new FormConfigGenerator();
                $formGenerator->setDebugCallable($debugCallable);
                $formGenerator->setContainer($this->container);
                $formGenerator->generate($genConf);
            } else {
                $this->debugLog("No form configuration found.");
            }


            $this->onGenerateAfter($genConf);


        } else {
            $this->error("Identifier not found: $identifier, in $file.");
        }
    }


    //--------------------------------------------
    //
    //--------------------------------------------
    /**
     * Sets the container.
     *
     * @param LightServiceContainerInterface $container
     */
    public function setContainer(LightServiceContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Sets the options.
     *
     * @param array $options
     */
    public function setOptions(array $options)
    {
        $this->options = $options;
    }


    /**
     * Sends a message to the debugLog, if the **useDebug** option is set to true.
     *
     * @param string $msg
     */
    public function debugLog(string $msg)
    {
        $useDebug = $this->options['useDebug'] ?? false;
        if (true === $useDebug) {

            /**
             * @var $logger LightLoggerService
             */
            $channel = $this->options['debugLogChannel'] ?? "real_generator.debug";
            $logger = $this->container->get("logger");
            $logger->log($msg, $channel);
        }
    }




    //--------------------------------------------
    //
    //--------------------------------------------
    /**
     * Throws an exception with the given error message.
     *
     * @param string $msg
     * @throws LightRealGeneratorException
     */
    protected function error(string $msg)
    {
        $this->debugLog("Error: " . $msg);
        throw new LightRealGeneratorException($msg);
    }


    /**
     * Hook called at the end of the @page(generate method).
     *
     * @param array $configBlock
     * @overrideMe
     */
    protected function onGenerateAfter(array $configBlock)
    {

    }
}