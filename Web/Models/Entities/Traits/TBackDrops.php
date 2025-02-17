<?php

declare(strict_types=1);

namespace openvk\Web\Models\Entities\Traits;

use openvk\Web\Models\Entities\Photo;
use openvk\Web\Models\Repositories\Photos;

trait TBackDrops
{
    public function getBackDropPictureURLs(): ?array
    {
        $photo1 = $this->getRecord()->backdrop_1;
        $photo2 = $this->getRecord()->backdrop_2;
        if (is_null($photo1) && is_null($photo2)) {
            return null;
        }

        $photo1obj = $photo2obj = null;
        if (!is_null($photo1)) {
            $photo1obj = (new Photos())->get($photo1);
        }
        if (!is_null($photo2)) {
            $photo2obj = (new Photos())->get($photo2);
        }

        if (is_null($photo1obj) && is_null($photo2obj)) {
            return null;
        }

        return [
            is_null($photo1obj) ? "" : $photo1obj->getURL(),
            is_null($photo2obj) ? "" : $photo2obj->getURL(),
        ];
    }

    public function setBackDropPictures(?Photo $first, ?Photo $second): void
    {
        if (!is_null($first)) {
            $this->stateChanges("backdrop_1", $first->getId());
        }

        if (!is_null($second)) {
            $this->stateChanges("backdrop_2", $second->getId());
        }
    }

    public function unsetBackDropPictures(): void
    {
        $this->stateChanges("backdrop_1", null);
        $this->stateChanges("backdrop_2", null);
    }
}
