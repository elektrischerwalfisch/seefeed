/**
 * Seefeed – render calendar events from JSON into [data-events] /
 * [data-event-detail] mounts.
 *
 * Loads events/venues via XHR, filters list mounts (upcoming|past|all|latest),
 * fills HTML <template> clones. Detail mounts read ?event=<id> (configurable).
 * Options: window.Seefeed before this script loads.
 *
 * @file        core/js/seefeed.js
 * @project     Seefeed
 * @author      elektrischerwalfisch
 * @since       2026-09
 * @requires    DOM with mounts and #event-{name} templates
 * @see         demo/index.php
 */

"use strict";

// --- Options (adapter may set window.Seefeed before this script) ----------
const cfg = window.Seefeed || {};
// Defaults assume runtime under data/; demo points at demo/sample-data/
const eventsUrl = cfg.eventsUrl || "../../data/events.json";
const venuesUrl = cfg.venuesUrl || "../../data/venues.json";
const dateLocale = cfg.dateLocale || "de-DE";
const dateDisplay = cfg.dateDisplay || {
    timeZone: "UTC",
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
};
const timeDisplay = cfg.timeDisplay || {
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
// Detail URL query key: ?event=<id>
const eventIdParam = cfg.eventIdParam || "event";
// Base URL for list → detail permalinks (omit = do not fill .event-permalink)
const eventDetailUrl =
    cfg.eventDetailUrl !== undefined ? cfg.eventDetailUrl : "";

// --- DOM --------------------------------------------------------------------
const listMounts = document.querySelectorAll("[data-events]");
const detailMounts = document.querySelectorAll("[data-event-detail]");

const setMountError = (message) => {
    listMounts.forEach((mount) => {
        mount.textContent = message;
    });
    detailMounts.forEach((mount) => {
        mount.textContent = message;
    });
};

// --- Load JSON --------------------------------------------------------------
const loadJson = (url, onSuccess) => {
    const xhr = new XMLHttpRequest();

    xhr.onload = function () {
        if (xhr.status != 200) {
            setMountError("Fehler beim Laden der Daten");
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

// Date-only: day/month/year + optional timeZone. Timed: day/month/year only (browser local TZ).
// Never include weekday here — that would change .event-date via formatDay.
const dateDisplayOptions = (isoDate) => {
    const options = {
        day: dateDisplay.day,
        month: dateDisplay.month,
        year: dateDisplay.year,
    };
    if (!hasTime(isoDate) && dateDisplay.timeZone) {
        options.timeZone = dateDisplay.timeZone;
    }
    return options;
};

// Same as dateDisplayOptions, plus weekday when set (for formatToParts / .event-weekday only)
const datePartsOptions = (isoDate) => {
    const options = dateDisplayOptions(isoDate);
    if (dateDisplay.weekday) {
        options.weekday = dateDisplay.weekday;
    }
    return options;
};

const formatDay = (isoDate) => {
    const date = new Date(isoDate);
    return date.toLocaleDateString(dateLocale, dateDisplayOptions(isoDate));
};

// Optional slots .event-day / .event-month / .event-year / .event-weekday
const formatDayParts = (isoDate) => {
    const date = new Date(isoDate);
    const parts = new Intl.DateTimeFormat(
        dateLocale,
        datePartsOptions(isoDate)
    ).formatToParts(date);
    const result = { day: "", month: "", year: "", weekday: "" };
    parts.forEach((part) => {
        if (
            part.type === "day" ||
            part.type === "month" ||
            part.type === "year" ||
            part.type === "weekday"
        ) {
            result[part.type] = part.value;
        }
    });
    return result;
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

// Fill one <time>: ISO on datetime; .event-date and optional day/month/year/weekday; .event-time
const fillInstant = (timeEl, isoDate, showDate, showTime) => {
    if (!timeEl) {
        return;
    }
    timeEl.dateTime = isoDate;
    fillText(timeEl, ".event-date", showDate ? formatDay(isoDate) : "");
    const dayParts = showDate
        ? formatDayParts(isoDate)
        : { day: "", month: "", year: "", weekday: "" };
    fillText(timeEl, ".event-day", dayParts.day);
    fillText(timeEl, ".event-month", dayParts.month);
    fillText(timeEl, ".event-year", dayParts.year);
    fillText(timeEl, ".event-weekday", dayParts.weekday);
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

// Shared field fill for list items and detail view
const fillEventIntoRoot = (root, eventItem, venuesByFn) => {
    const venue = venuesByFn[eventItem.location];

    fillText(root, ".event-title", eventItem.summary);
    fillDateRange(root, eventItem.start, eventItem.end);
    fillCategories(root, eventItem.categories);
    fillText(root, ".event-location", eventItem.location);
    fillText(root, ".event-description", eventItem.description || "");
    if (venue && venue.street) {
        fillText(
            root,
            ".event-address",
            venue.street + "\n" + venue.postalCode + " " + venue.locality
        );
    }
    fillLink(root, ".event-url", venue && venue.url);
    fillImage(root, ".event-image", eventItem.attach, eventItem.summary);
    fillPermalink(root, eventItem);
};

// Optional .event-permalink → detail page with ?event=<id>
const fillPermalink = (root, eventItem) => {
    const el = root.querySelector(".event-permalink");
    if (!el || !eventDetailUrl || !eventItem.id) {
        return;
    }
    const link = new URL(eventDetailUrl, window.location.href);
    link.searchParams.set(eventIdParam, eventItem.id);
    el.href = link.pathname + link.search + link.hash;
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

const eventIdFromQuery = () => {
    const params = new URLSearchParams(window.location.search);
    return params.get(eventIdParam) || "";
};

const findEventById = (events, id) => {
    if (!id) {
        return null;
    }
    return events.find((eventItem) => eventItem.id === id) || null;
};

// Render one list mount: filter + template from data attributes
const showListMount = (mount, events, venuesByFn) => {
    const filterMode = (mount.dataset.filter || "all").toLowerCase();
    const template = resolveTemplate(mount.dataset.template);
    if (!template) {
        mount.textContent = "Template not found";
        return;
    }

    const filtered = filterEvents(events, filterMode);
    const eventList = document.createElement("ol");
    eventList.className = "eventlist";

    filtered.forEach((eventItem) => {
        const clone = template.content.cloneNode(true);
        fillEventIntoRoot(clone, eventItem, venuesByFn);
        eventList.appendChild(clone);
    });

    mount.replaceChildren(eventList);
};

// Render one detail mount from ?event=<id> (default template: detail)
const showDetailMount = (mount, events, venuesByFn) => {
    const template = resolveTemplate(mount.dataset.template || "detail");
    if (!template) {
        mount.textContent = "Template not found";
        return;
    }

    const id = eventIdFromQuery();
    if (!id) {
        mount.replaceChildren();
        return;
    }

    const eventItem = findEventById(events, id);
    if (!eventItem) {
        mount.textContent = "Event not found";
        return;
    }

    const clone = template.content.cloneNode(true);
    fillEventIntoRoot(clone, eventItem, venuesByFn);
    mount.replaceChildren(clone);
};

// Render list and detail mounts from one shared JSON load
const showEvents = (eventsData, venuesData) => {
    const venuesByFn = {};
    venuesData.venues.forEach((venue) => {
        venuesByFn[venue.fn] = venue;
    });

    const events = eventsData.events || [];
    listMounts.forEach((mount) => {
        showListMount(mount, events, venuesByFn);
    });
    detailMounts.forEach((mount) => {
        showDetailMount(mount, events, venuesByFn);
    });
};

// Load data and render
loadJson(eventsUrl, (eventsData) => {
    loadJson(venuesUrl, (venuesData) => {
        showEvents(eventsData, venuesData);
    });
});
