<?php

namespace Anibalealvarezs\ShopifyApi\Enums;

enum SortOptions: string
{
    case idAsc = 'id asc';
    case idDesc = 'id desc';
    case createdAtAsc = 'created_at asc';
    case createdAtDesc = 'created_at desc';
    case updatedAtAsc = 'updated_at asc';
    case updatedAtDesc = 'updated_at desc';
    case processedAtAsc = 'processed_at asc';
    case processedAtDesc = 'processed_at desc';
}
