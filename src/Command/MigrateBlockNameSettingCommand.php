<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\PageBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Repository\DocumentRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Migrates the name setting of all blocks into a code setting.
 */
class MigrateBlockNameSettingCommand extends BaseCommand
{
    public const CONTAINER_TYPE = 'sonata.page.block.container';

    public function configure()
    {
        $this->setName('sonata:page:migrate-block-setting');
        $this->addOption(
            'class',
            null,
            InputOption::VALUE_OPTIONAL,
            'Block document class',
            'Application\Sonata\PageBundle\Document\Block'
        );
        $this->addOption(
            'update-name',
            null,
            InputOption::VALUE_OPTIONAL,
            'update name field from code setting',
            false
        );
        $this->setDescription('Migrate the "name" setting of all blocks into a "code" setting and remove unused "orientation" setting on "'.self::CONTAINER_TYPE.'" blocks');
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $count = 0;
        $repository = $this->getRepository($input->getOption('class'));
        $blocks = $repository->findAll();

        foreach ($blocks as $block) {
            $settings = $block->getSettings();

            // Remove orientation option if it exists
            if (self::CONTAINER_TYPE === $block->getType() && \array_key_exists('orientation', $settings)) {
                unset($settings['orientation']);
                $block->setSettings($settings);

                $this->getDocumentManager()->persist($block);
                ++$count;
            }

            // only change rows that need to
            if (isset($settings['name'])) {
                // switch name with code key
                $settings['code'] = $settings['name'];
                unset($settings['name']);
                $block->setSettings($settings);

                // update name from code if option is set
                if (true === $input->getOption('update-name')) {
                    $block->setName($block->getSetting('code'));
                }

                $this->getDocumentManager()->persist($block);
                ++$count;
            }

            if ($count % 100) {
                $this->getDocumentManager()->flush();
            }
        }

        $this->getDocumentManager()->flush();

        $output->writeln("<info>Migrated $count blocks</info>");
    }

    /**
     * Returns the entity repository for given class name.
     *
     * @param string $class Entity class name
     *
     * @return EntityRepository
     */
    protected function getRepository($class)
    {
        return $this->getDocumentManager()->getRepository($class);
    }

    /**
     * Returns the entity manager.
     *
     * @return DocumentManager
     */
    protected function getDocumentManager()
    {
        return $this->getContainer()->get('doctrine_mongodb.odm.document_manager');
    }
}
