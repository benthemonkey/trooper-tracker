// resources/js/parsers/theLegionParser.js

import { parseMessage } from './parseMessage.js';
import { formatUsDateTime, parseUsDateTime } from './parseUtils.js';

export function theLegionParser(message) {
    const parsed = parseMessage(message);

    // garrison request forms label some fields differently; the first label present wins
    const field = (...labels) => labels.map(label => parsed[label]).find(value => value !== undefined) ?? null;

    return {
        contact_name: field('Contact Name', 'Contact'),
        contact_phone: field('Contact Phone Number', 'Phone #'),
        contact_email: field('Contact Email', 'Email'),

        name: field('Event Name', 'Event'),
        venue: parsed['Venue'] ?? null,
        venue_address: field('Venue address', 'Address'),

        event_start: parsed['Event Start'] ? formatUsDateTime(parseUsDateTime(parsed['Event Start'])) : null,

        event_end: parsed['Event End'] ? formatUsDateTime(parseUsDateTime(parsed['Event End'])) : null,

        event_website: parsed['Event Website'] ?? null,

        expected_attendees: parsed['Expected number of attendees'] ?? null,
        requested_number_characters: parsed['Requested number of characters'] ?? null,
        requested_character_types: parsed['Requested character types'] ?? null,

        secure_staging_area: (parsed['Secure changing/staging area'] ?? '') === 'Yes',

        allow_blasters: (parsed['Can troopers carry blasters'] ?? '') === 'Yes',

        allow_props: (parsed['Can troopers carry/bring props like lightsabers and staffs'] ?? '') === 'Yes',

        parking_available: (parsed['Is parking available'] ?? '') === 'Yes',

        accessible: (parsed['Is venue accessible to those with limited mobility'] ?? '') === 'Yes',

        amenities: parsed['Amenities available at venue'] ?? null,
        comments: parsed['Comments'] ?? null,
        referred_by: parsed['Referred by'] ?? null,

        source: message
    };
}
