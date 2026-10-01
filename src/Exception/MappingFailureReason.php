<?php

declare(strict_types=1);

namespace Sirix\ObjectMapper\Exception;

/**
 * Coarse, boundary-level reason for a mapping execution failure.
 *
 * Values are fixed and intentionally coarse: they identify the failing
 * boundary, not the exact code path. They never carry source data, provider
 * identifiers, or original exception messages.
 */
enum MappingFailureReason: string
{
    case GeneratedMappingFailed = 'generated_mapping_failed';

    case CustomMapperFailed = 'custom_mapper_failed';

    case ProviderUnavailable = 'provider_unavailable';

    case ProviderResolutionFailed = 'provider_resolution_failed';

    case UnexpectedTarget = 'unexpected_target';

    case CollectionElementType = 'collection_element_type';
}
