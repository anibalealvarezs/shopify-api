<?php

namespace Anibalealvarezs\ShopifyApi\Enums;

enum CollectionPublishedStatus: string
{
    case published = 'published';
    case unpublished = 'unpublished';
    case any = 'any';
}
