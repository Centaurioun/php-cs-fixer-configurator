<?php
namespace MLocati\PhpCsFixerConfigurator;

use Exception;
use PhpCsFixer\Config;
use PhpCsFixer\Console\Application;
use PhpCsFixer\Fixer\ConfigurableFixerInterface;
use PhpCsFixer\Fixer\ConfigurationDefinitionFixerInterface;
use PhpCsFixer\Fixer\DefinedFixerInterface;
use PhpCsFixer\FixerDefinition\FileSpecificCodeSampleInterface;
use PhpCsFixer\FixerFactory;
use PhpCsFixer\RuleSet;
use PhpCsFixer\StdinFileInfo;
use PhpCsFixer\Tokenizer\Tokens;
use Throwable;

class DataExtractor
{
    /**
     * Get the PHP-CS-Fixer version.
     *
     * @return string
     */
    public function getVersion()
    {
        return Application::VERSION;
    }

    /**
     * Get the default indent.
     *
     * @return string
     */
    public function getDefaultIndent()
    {
        return (new Config())->getIndent();
    }

    /**
     * Get the default line ending.
     *
     * @return string
     */
    public function getDefaultLineEnding()
    {
        return (new Config())->getLineEnding();
    }

    /**
     * @return array
     */
    public function getFixers()
    {
        $result = [];
        $factory = FixerFactory::create()->registerBuiltInFixers();
        foreach ($factory->getFixers() as $fixer) {
            $fixerData = [];
            if ($fixer->isRisky()) {
                $fixerData['risky'] = true;
            }
            if ($fixer instanceof ConfigurationDefinitionFixerInterface) {
                foreach ($fixer->getConfigurationDefinition()->getOptions() as $option) {
                    $o = [
                        'name' => $option->getName(),
                    ];
                    $s = (string) $option->getDescription();
                    if ($s !== '') {
                        $o['description'] = $s;
                    }
                    if ($option->hasDefault()) {
                        $o['defaultValue'] = $option->getDefault();
                    }
                    $allowedTypes = $option->getAllowedTypes();
                    if ($allowedTypes !== null) {
                        $o['allowedTypes'] = $allowedTypes;
                    }
                    $allowedValues = $option->getAllowedValues();
                    if ($allowedValues !== null) {
                        foreach ($allowedValues as $allowedValue) {
                            if ($allowedValue !== null && !is_scalar($allowedValue)) {
                                $allowedValues = null;
                                break;
                            }
                        }
                        if ($allowedValues !== null) {
                            $o['allowedValues'] = $allowedValues;
                        }
                    }
                    if (!isset($fixerData['configuration'])) {
                        $fixerData['configuration'] = [];
                    }
                    $fixerData['configuration'][] = $o;
                }
                usort($fixerData['configuration'], function (array $option1, array $option2) {
                    return strcasecmp($option1['name'], $option2['name']);
                });
            }
            if ($fixer instanceof DefinedFixerInterface) {
                $definition = $fixer->getDefinition();
                $s = (string) $definition->getSummary();
                if ($s !== '') {
                    $fixerData['summary'] = $s;
                }
                $s = (string) $definition->getDescription();
                if ($s !== '') {
                    $fixerData['description'] = $s;
                }
                $s = (string) $definition->getRiskyDescription();
                if ($s !== '') {
                    $fixerData['riskyDescription'] = $s;
                }
                foreach ($definition->getCodeSamples() as $codeSample) {
                    $old = $codeSample->getCode();
                    $configuration = $codeSample->getConfiguration();
                    $new = null;
                    try {
                        $tokens = Tokens::fromCode($old);
                    } catch (Exception $x) {
                        $new = '*** Tokens::fromCode() failed with ' . get_class($x) . ': ' . $x->getMessage() . ' *** ';
                    } catch (Throwable $x) {
                        $new = '*** Tokens::fromCode() failed with ' . get_class($x) . ': ' . $x->getMessage() . ' *** ';
                    }
                    if ($new === null) {
                        if ($fixer instanceof ConfigurableFixerInterface) {
                            try {
                                $fixer->configure($configuration);
                            } catch (Exception $x) {
                                $new = '*** FixerInterface::configure() failed with ' . get_class($x) . ': ' . $x->getMessage() . ' *** ';
                            } catch (Exception $x) {
                                $new = '*** FixerInterface::configure() failed with ' . get_class($x) . ': ' . $x->getMessage() . ' *** ';
                            }
                        }
                        if ($new === null) {
                            $file = $codeSample instanceof FileSpecificCodeSampleInterface ? $codeSample->getSplFileInfo() : new StdinFileInfo();
                            $fixer->fix($file, $tokens);
                            $new = $tokens->generateCode();
                        }
                    }
                    if (!isset($fixerData['codeSamples'])) {
                        $fixerData['codeSamples'] = [];
                    }
                    $codeSampleData = [
                        'from' => $old,
                        'to' => $new,
                    ];
                    if ($configuration !== null) {
                        $codeSampleData['configuration'] = $this->anonymizePaths($configuration);
                    }
                    $fixerData['codeSamples'][] = $codeSampleData;
                }
            }
            $result[$fixer->getName()] = $fixerData;
        }
        ksort($result, SORT_FLAG_CASE);

        return $result;
    }

    /**
     * @return array
     */
    public function getSets()
    {
        $result = [];
        $setNames = RuleSet::create()->getSetDefinitionNames();
        foreach ($setNames as $setName) {
            $ruleSet = RuleSet::create([$setName => true]);
            $config = [];
            foreach ($ruleSet->getRules() as $ruleName => $ruleConfiguration) {
                $config[$ruleName] = $ruleConfiguration === true ? null : $ruleConfiguration;
            }
            $result[$setName] = $config;
        }

        return $result;
    }

    /**
     * @param mixed $value
     *
     * @return mixed
     */
    private function anonymizePaths($value)
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = $this->anonymizePaths($item);
            }
        } else {
            $result = $value;
            if (is_string($value)) {
                $valueNormalized = str_replace(DIRECTORY_SEPARATOR, '/', $value);
                $rootNormalized = rtrim(str_replace(DIRECTORY_SEPARATOR, '/', dirname(__DIR__, 1)), '/') . '/';
                if (strpos($valueNormalized, $rootNormalized) === 0) {
                    $result = '/path/to/' . substr($valueNormalized, strlen($rootNormalized));
                }
            }
        }

        return $result;
    }
}
