<?php

declare(strict_types=1);

/*
 * This file is part of Gallery Creator Bundle.
 *
 * (c) Marko Cupic 2023 <m.cupic@gmx.ch>
 * @license GPL-3.0-or-later
 * For the full copyright and license information,
 * please view the LICENSE file that was distributed with this source code.
 * @link https://github.com/markocupic/gallery-creator-bundle
 */

namespace Markocupic\GalleryCreatorBundle\Util;

use Contao\Config;
use Contao\ContentModel;
use Contao\CoreBundle\File\Metadata;
use Contao\CoreBundle\Routing\ScopeMatcher;
use Contao\File;
use Contao\FilesModel;
use Contao\Frontend;
use Contao\Input;
use Contao\StringUtil;
use Contao\System;
use Contao\UserModel;
use Markocupic\GalleryCreatorBundle\Model\GalleryCreatorPicturesModel;
use Symfony\Component\HttpFoundation\RequestStack;

class PictureUtil
{
    public function __construct(
        private readonly ScopeMatcher $scopeMatcher,
        private readonly RequestStack $requestStack,
        private readonly bool $galleryCreatorReadExifMetaData,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @throws \Exception
     */
    public function getPictureData(GalleryCreatorPicturesModel $pictureModel, ContentModel $contentElementModel): array|null
    {
        global $objPage;

        $tlFilesUrl = System::getContainer()->get('contao.assets.files_context')->getStaticUrl();

        $request = $this->requestStack->getCurrentRequest();

        if (null === ($filesModel = FilesModel::findByUuid($pictureModel->uuid))) {
            return null;
        }

        // Meta
        $arrMeta = Frontend::getMetaData($filesModel->meta, $objPage->language);

        if (!isset($arrMeta['title'])) {
            $arrMeta['title'] = '';
        }

        $arrMeta['title'] = $pictureModel->caption ?? $arrMeta['title'];

        // Get the thumb dimensions
        $arrSize = StringUtil::deserialize($contentElementModel->gcSizeDetailView);

        $href = null;
        $localMediaSrc = null;
        $socialMediaSrc = null;

        if ($request && $this->scopeMatcher->isFrontendRequest($request)) {
            // e.g. youtube or vimeo
            $href = $pictureModel->socialMediaSRC ?: null;
            $socialMediaSrc = $pictureModel->socialMediaSRC ?: null;

            // Local media
            if (null !== ($objMovieFile = FilesModel::findByUuid($pictureModel->localMediaSRC))) {
                $href = $objMovieFile->path;
                $localMediaSrc = $tlFilesUrl.$objMovieFile->path;
            }

            // Use the image path as default
            if (null === $href && $contentElementModel->gcFullSize) {
                $href = $filesModel->path;
            }

            $href = $href ? $tlFilesUrl.$href : null;
        }

        $ownerModel = UserModel::findByPk($pictureModel->cuser);

        // Build the array
        return [
            'ownerRow' => $ownerModel ? $ownerModel->row() : [],
            'filesRow' => $filesModel->row(),
            'pictureRow' => $pictureModel->row(),
            'albumRow' => $pictureModel->getRelated('pid')->row(),
            'meta' => $arrMeta,
            'href' => $href,
            'localMediaSrc' => $localMediaSrc,
            'socialMediaSrc' => $socialMediaSrc,
            'exif' => $this->galleryCreatorReadExifMetaData ? $this->getExif(new File($filesModel->path)) : [],
            'singleImageUrl' => StringUtil::ampersand($objPage->getFrontendUrl((Config::get('useAutoItem') ? '/' : '/items/').Input::get('items').'/img/'.$filesModel->name, $objPage->language)),
            'figureUuid' => $pictureModel->addCustomThumb && $pictureModel->customThumb ? $pictureModel->customThumb : $filesModel->uuid,
            'figureSize' => !empty($arrSize) ? $arrSize : null,
            'figureHref' => $href,
            'figureOptions' => [
                'metadata' => new Metadata($arrMeta),
                'enableLightbox' => (bool) $contentElementModel->gcFullSize,
                'lightboxGroupIdentifier' => sprintf('data-lightbox="lb%s"', $pictureModel->pid),
                //'lightboxSize' => '_big_size',
                'linkHref' => $href,
            ],
        ];
    }

    /**
     * @return array<string>
     */
    public function getExif(File $file): array
    {
        // Exif
        try {
            $exif = \is_callable('exif_read_data') ? exif_read_data($this->projectDir.'/'.$file->path) : ['info' => "The function 'exif_read_data()' is not available on this server."];
        } catch (\Exception $e) {
            $exif = ['info' => "The function 'exif_read_data()' is not available on this server."];
        }

        return $exif;
    }

    public function pictureExists(GalleryCreatorPicturesModel $picturesModel): bool
    {
        $objFile = FilesModel::findByUuid($picturesModel->uuid);

        if (null !== $objFile) {
            return is_file($objFile->getAbsolutePath());
        }

        return false;
    }
}
