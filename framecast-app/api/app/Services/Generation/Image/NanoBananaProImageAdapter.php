<?php

namespace App\Services\Generation\Image;

/**
 * Google Nano Banana Pro (Gemini 3 Pro Image) on Replicate — higher fidelity
 * than nano-banana (2.5 Flash Image), strongest identity + reference editing.
 * Same request/response shape as the base adapter; only the model slug differs.
 */
class NanoBananaProImageAdapter extends NanoBananaImageAdapter
{
    protected function modelSlug(): string
    {
        return 'google/nano-banana-pro';
    }
}
