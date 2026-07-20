# Neighborhood Histories

The Neighborhood Histories module provisions and maintains the scalable directory structure for TimeWalk Japan.

## Managed pages

- `/en/neighborhood-histories/` — national entry page
- `/en/tokyo/neighborhood-histories/` — Tokyo directory

The Tokyo and Stories pages receive contextual links at render time. The Home page receives a latest-neighborhood-histories section only after at least three articles exist.

## Article identification and URL

Use the normal WordPress `post` type and assign the category:

- Name: `Neighborhood Histories`
- Slug: `neighborhood-histories`

Set `_twj_nh_city_slug` and use a post slug ending in `-history`. The public permalink is generated as:

`/en/{city-slug}/{post-slug}/`

Example: city slug `tokyo` plus post slug `shinjuku-history` produces `/en/tokyo/shinjuku-history/`.

## Registered metadata

All fields are registered for normal posts and exposed to authenticated REST editing.

- `_twj_neighborhood_history` — `1`
- `_twj_nh_country`
- `_twj_nh_prefecture`
- `_twj_nh_city`
- `_twj_nh_city_slug`
- `_twj_nh_municipality`
- `_twj_nh_broad_area`
- `_twj_nh_area_en`
- `_twj_nh_area_ja`
- `_twj_nh_area_slug`
- `_twj_nh_alternative_names`
- `_twj_nh_nearest_stations`
- `_twj_nh_latitude`
- `_twj_nh_longitude`
- `_twj_nh_historical_character`
- `_twj_nh_main_periods`
- `_twj_nh_short_description`
- `_twj_nh_related_walk_url`
- `_twj_nh_related_stories`
- `_twj_nh_featured_priority`
- `_twj_nh_subtitle`
- `_twj_nh_walk_available`

Comma-separated metadata is synchronized to the directory taxonomies when the post is saved.

## Registered taxonomies

- `twj_nh_broad_area`
- `twj_nh_municipality`
- `twj_nh_character`
- `twj_nh_period`
- `twj_nh_station`

Taxonomy archives are not public. They exist for administration, REST input and directory filtering.

## Required fields for a Tokyo article

At minimum, provide:

1. Category `Neighborhood Histories`
2. Post slug such as `shinjuku-history`
3. `_twj_nh_city_slug`: `tokyo`
4. `_twj_nh_area_en`
5. `_twj_nh_area_ja`
6. `_twj_nh_municipality`
7. `_twj_nh_broad_area`
8. `_twj_nh_nearest_stations`
9. `_twj_nh_historical_character`
10. `_twj_nh_short_description`
11. Featured Image and alt text

The short description should state what the neighborhood changed from and into, rather than using generic wording.

## Article pattern

In the block editor, insert the pattern:

`TimeWalk Japan > Neighborhood History Article`

The title, subtitle and Featured Image are rendered by the normal post presentation. The pattern supplies Quick Facts and these sections:

- Where Is This Neighborhood?
- The Neighborhood Today
- Geography and Early Settlement
- Historical Development
- Major Turning Points
- How the Past Shaped the Present
- What You Can Still See Today
- Walk This History, only when a related walk exists
- Related Stories, only when related items exist
- Sources and Further Reading

## Directory behavior

- Server-rendered search over title, content, excerpt, English and Japanese area names, alternative names, stations, municipality and short description
- Filters for broad area, municipality, historical character and walk availability
- 24 articles per page with standard pagination
- No empty area cards or map
- Map appears only after at least five articles have coordinates and loads Leaflet only when opened
- Filter and search URLs are `noindex,follow` and canonicalize to the directory page
- Category archives redirect to the national directory

## Diagnostic endpoint

`/en/wp-json/timewalk/v1/neighborhood-status`

This reports the module version, page IDs and URLs, category details, article count, page size, map threshold and registered taxonomies.
