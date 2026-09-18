/**
 * Seefeed – render calendar events from JSON into [data-events] mounts.
 *
 * Loads events/venues via XHR, filters (upcoming|past|all|latest), fills HTML
 * <template> clones. Options: window.Seefeed before this script loads.
 *
 * @file        core/js/seefeed.js
 * @project     Seefeed
 * @author      Seefeed
 * @version     0.1.0
 * @since       2026-09
 * @requires    DOM with [data-events] mounts and #event-{name} templates
 * @see         adapters/plain/index.php (demo config)
 */

"use strict";

// --- Options (adapter may set window.Seefeed before this script) ----------
const cfg = window.Seefeed || {};
// Defaults assume runtime under data/; plain adapter points at demo-data/
const eventsUrl = cfg.eventsUrl || "../../data/events.json";
const venuesUrl = cfg.venuesUrl || "../../data/venues.json";
const dateLocale = cfg.dateLocale || "de-DE";
const dateDisplay = cfg.dateDisplay || {
    // timeZone: "UTC" (no shift for date-only) | "Europe/Berlin" | … (IANA); omit = browser local
    // day: "numeric" (5) | "2-digit" (05)
    // month: "numeric" (1) | "2-digit" (01) | "long" (Januar) | "short" (Jan.) | "narrow" (J)
    // year: "numeric" (2026) | "2-digit" (26)
    timeZone: "UTC",
    day: "2-digit",
    month: "2-digit",
    year: "2-digit",
};
const timeDisplay = cfg.timeDisplay || {
    // hour: "numeric" (9) | "2-digit" (09)
    // minute: "numeric" (5) | "2-digit" (05)
    // second: "numeric" (7) | "2-digit" (07) (optional)
    // hour12: true (9:05 AM) | false (09:05) (optional; omit = locale default)
    hour: "2-digit",
    minute: "2-digit",
};
const timeSuffix = cfg.timeSuffix !== undefined ? cfg.timeSuffix : " Uhr";
// Image folder next to events.json; empty string = use ATTACH URI as-is
const eventImageBase =
    cfg.eventImageBase !== undefined
        ? cfg.eventImageBase
        : eventsUrl.replace(/[^/]+$/, "") + "img/";
// data-filter on [data-events]: "upcoming" | "past" | "all" | "latest" (default all)
// data-template: short name → template id "event-{name}" (default "full"; PHP includes fragments)
const latestCount = cfg.latestCount !== undefined ? cfg.latestCount : 3;
const upcomingCount = cfg.upcomingCount !== undefined ? cfg.upcomingCount : 3;

// --- DOM --------------------------------------------------------------------
const mounts = document.querySelectorAll("[data-events]");

// --- Load JSON --------------------------------------------------------------
const loadJson = (url, onSuccess) => {
    const xhr = new XMLHttpRequest();

    xhr.onload = function () {
        if (xhr.status != 200) {
            mounts.forEach((mount) => {
                mount.textContent = "Fehler beim Laden der Daten";
            });
            return;
        }

        onSuccess(xhr.response);
    };

    xhr.open("GET", url);
    xhr.responseType = "json";
    xhr.send();
};

// Prepare different handling for dates with and without time
// ISO date is YYYY-MM-DD (10 chars); "T" separates date and time (e.g. 2026-09-18T19:00:00)
const datePart = (isoDate) => isoDate.slice(0, 10);
const hasTime = (isoDate) => isoDate.includes("T");

const formatDay = (isoDate) => {
    const date = new Date(isoDate);
    if (!hasTime(isoDate)) {
        return date.toLocaleDateString(dateLocale, dateDisplay);
    }
    return date.toLocaleDateString(dateLocale, {
        day: dateDisplay.day,
        month: dateDisplay.month,
        year: dateDisplay.year,
    });
};

// Clock time in de-DE (HH:MM) plus suffix (Uhr) which is added by + timeSuffix;
const formatTime = (isoDate) => {
    const date = new Date(isoDate);
    return date.toLocaleTimeString(dateLocale, timeDisplay) + timeSuffix;
};

// Skip missing template fields so one renderer works with partial templates
const fillText = (root, selector, value) => {
    const el = root.querySelector(selector);
    if (!el) {
        return;
    }
    el.textContent = value;
};

// Insert an <a href> when the template slot exists and url is set; text equals the URL
const fillLink = (root, selector, url) => {
    const el = root.querySelector(selector);
    if (!el || !url) {
        return;
    }
    const link = document.createElement("a");
    link.href = url;
    link.textContent = url;
    el.appendChild(link);
};

// Insert <img> when the template slot and ATTACH exist; with eventImageBase use basename only
const fillImage = (root, selector, attach, altText) => {
    const el = root.querySelector(selector);
    if (!el || !attach) {
        return;
    }
    let src = attach;
    if (eventImageBase) {
        const filename = attach.includes("/")
            ? attach.replace(/\/+$/, "").split("/").pop()
            : attach;
        if (!filename) {
            return;
        }
        src = eventImageBase + filename;
    }
    const img = document.createElement("img");
    img.src = src;
    img.alt = altText || "";
    el.appendChild(img);
};

// Fill one <time>: ISO on datetime, date and time in separate spans (empty span is hidden)
const fillInstant = (timeEl, isoDate, showDate, showTime) => {
    if (!timeEl) {
        return;
    }
    timeEl.dateTime = isoDate;
    fillText(timeEl, ".event-date", showDate ? formatDay(isoDate) : "");
    fillText(timeEl, ".event-time", showTime ? formatTime(isoDate) : "");
};

// Fill the date range elements (different handling for dates with and without time)
const fillDateRange = (clone, startIso, endIso) => {
    const startEl = clone.querySelector(".event-start");
    const endEl = clone.querySelector(".event-end");
    const sepEl = clone.querySelector(".event-date-sep");
    if (!startEl) {
        return;
    }
    const sameDay = datePart(startIso) === datePart(endIso);
    const timed = hasTime(startIso) || hasTime(endIso);

    // If the start and end dates are the same day and there is no time, show only the date
    if (sameDay && !timed) {
        fillInstant(startEl, startIso, true, false);
        if (sepEl) {
            sepEl.hidden = true;
        }
        if (endEl) {
            endEl.hidden = true;
        }
        return;
    }

    // If the start and end dates are the same day and there is time, show the date and time
    if (sameDay && timed) {
        fillInstant(startEl, startIso, true, true);
        fillInstant(endEl, endIso, false, true);
        if (sepEl) {
            sepEl.hidden = false;
        }
        return;
    }

    // If the start and end dates are not the same day and there is no time, show the date
    if (!timed) {
        fillInstant(startEl, startIso, true, false);
        fillInstant(endEl, endIso, true, false);
        if (sepEl) {
            sepEl.hidden = false;
        }
        return;
    }

    // If the start and end dates are not the same day and there is time, show the date and time
    fillInstant(startEl, startIso, true, true);
    fillInstant(endEl, endIso, true, true);
    if (sepEl) {
        sepEl.hidden = false;
    }
};

// Append one <li> per category when the template has .event-categories
const fillCategories = (clone, categories) => {
    const listEl = clone.querySelector(".event-categories");
    if (!listEl) {
        return;
    }
    (categories || []).forEach((category) => {
        const item = document.createElement("li");
        item.textContent = category;
        listEl.appendChild(item);
    });
};

// Calendar day in UTC (YYYY-MM-DD), aligned with date-only event fields
const todayUtc = () => new Date().toISOString().slice(0, 10);

const eventEndDay = (eventItem) => datePart(eventItem.end || eventItem.start || "");

// data-filter: upcoming = end >= today, next N by start (N = upcomingCount);
// past = end < today; latest = newest N by start (N = latestCount); all = no filter
const filterEvents = (events, mode) => {
    if (mode === "all" || !mode) {
        return events;
    }
    if (mode === "latest") {
        return events
            .slice()
            .sort((a, b) => (b.start || "").localeCompare(a.start || ""))
            .slice(0, latestCount);
    }
    const today = todayUtc();
    if (mode === "upcoming") {
        return events
            .filter((eventItem) => {
                const endDay = eventEndDay(eventItem);
                return endDay && endDay >= today;
            })
            .slice()
            .sort((a, b) => (a.start || "").localeCompare(b.start || ""))
            .slice(0, upcomingCount);
    }
    return events.filter((eventItem) => {
        const endDay = eventEndDay(eventItem);
        if (!endDay) {
            return false;
        }
        if (mode === "past") {
            return endDay < today;
        }
        return true;
    });
};

const resolveTemplate = (name) => {
    const id = "event-" + (name || "full");
    return document.getElementById(id);
};

// Render one mount: filter + template from data attributes
const showMount = (mount, events, venuesByFn) => {
    const filterMode = (mount.dataset.filter || "all").toLowerCase();
    const template = resolveTemplate(mount.dataset.template);
    if (!template) {
        mount.textContent = "Template nicht gefunden";
        return;
    }

    const filtered = filterEvents(events, filterMode);
    const eventList = document.createElement("ol");
    eventList.className = "eventlist";

    filtered.forEach((eventItem) => {
        const clone = template.content.cloneNode(true);
        const venue = venuesByFn[eventItem.location];

        fillText(clone, ".event-title", eventItem.summary);
        fillDateRange(clone, eventItem.start, eventItem.end);
        fillCategories(clone, eventItem.categories);
        fillText(clone, ".event-location", eventItem.location);
        fillText(clone, ".event-description", eventItem.description || "");
        if (venue && venue.street) {
            fillText(
                clone,
                ".event-address",
                venue.street + ", " + venue.postalCode + " " + venue.locality
            );
        }
        fillLink(clone, ".event-url", venue && venue.url);
        fillImage(clone, ".event-image", eventItem.attach, eventItem.summary);

        eventList.appendChild(clone);
    });

    mount.replaceChildren(eventList);
};

// Render all [data-events] mounts from one shared JSON load
const showEvents = (eventsData, venuesData) => {
    const venuesByFn = {};
    venuesData.venues.forEach((venue) => {
        venuesByFn[venue.fn] = venue;
    });

    const events = eventsData.events || [];
    mounts.forEach((mount) => {
        showMount(mount, events, venuesByFn);
    });
};

// Load data and render
loadJson(eventsUrl, (eventsData) => {
    loadJson(venuesUrl, (venuesData) => {
        showEvents(eventsData, venuesData);
    });
});
