import React, { useEffect, useMemo, useState } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import axios from "axios";
import CustomerLayout from "@/Layouts/CustomerLayout";

function Popup({ open, title, message, onClose }) {
  if (!open) return null;
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h3 className="text-lg font-semibold">{title}</h3>
            <p className="mt-1 text-sm text-gray-600 whitespace-pre-line">{message}</p>
          </div>
          <button
            onClick={onClose}
            className="rounded-lg px-2 py-1 text-gray-500 hover:bg-gray-100"
            aria-label="Close"
          >
            ✕
          </button>
        </div>
        <div className="mt-4 flex justify-end">
          <button
            onClick={onClose}
            className="rounded-xl bg-black px-4 py-2 text-sm font-semibold text-white"
          >
            OK
          </button>
        </div>
      </div>
    </div>
  );
}

function parseTmpSlotId(tmpId) {
  if (!tmpId || typeof tmpId !== "string") return null;
  if (!tmpId.startsWith("TMP:")) return null;
  const parts = tmpId.split(":");
  if (parts.length < 4) return null;
  return {
    service_id: Number(parts[1] || 0),
    slot_date: parts[2],
    start_time: parts[3],
  };
}

export default function Schedule() {
  const { cartItems, flash, auth } = usePage().props;
  const username = auth?.user?.name || "Guest";
  const items = cartItems || [];

  const toYmd = (dateObj) => {
    const y = dateObj.getFullYear();
    const m = String(dateObj.getMonth() + 1).padStart(2, "0");
    const d = String(dateObj.getDate()).padStart(2, "0");
    return `${y}-${m}-${d}`;
  };

  /* ─── stepper state: which cart item we're scheduling ─── */
  const [stepIdx, setStepIdx] = useState(0);
  // picks[index] = { slot_id, slot_date, start_time, end_time }
  const [picks, setPicks] = useState({});

  const item = items[stepIdx];
  const service = item?.service;
  const totalSteps = items.length;

  const [popup, setPopup] = useState({ open: false, title: "", message: "" });

  useEffect(() => {
    if (flash?.error) {
      setPopup({ open: true, title: "Something went wrong", message: flash.error });
    }
  }, [flash]);

  const [month, setMonth] = useState(() => {
    const d = new Date();
    return new Date(d.getFullYear(), d.getMonth(), 1);
  });

  const [availableDates, setAvailableDates] = useState([]);
  const [selectedDate, setSelectedDate] = useState(null);
  const [slots, setSlots] = useState([]);
  const [selectedSlotId, setSelectedSlotId] = useState(null);
  const [selectedStartTime, setSelectedStartTime] = useState(null);
  const [loadingDates, setLoadingDates] = useState(false);
  const [loadingSlots, setLoadingSlots] = useState(false);

  const monthISO = useMemo(() => {
    const y = month.getFullYear();
    const m = String(month.getMonth() + 1).padStart(2, "0");
    return `${y}-${m}-01`;
  }, [month]);

  const monthLabel = useMemo(() => {
    return month.toLocaleString("en-MY", { month: "long", year: "numeric" });
  }, [month]);

  const weekday = ["Su", "Mo", "Tu", "We", "Th", "Fr", "Sa"];

  const daysGrid = useMemo(() => {
    const y = month.getFullYear();
    const m = month.getMonth();
    const firstDay = new Date(y, m, 1).getDay();
    const lastDate = new Date(y, m + 1, 0).getDate();
    const cells = [];
    for (let i = 0; i < firstDay; i++) cells.push(null);
    for (let d = 1; d <= lastDate; d++) {
      cells.push(toYmd(new Date(y, m, d)));
    }
    return cells;
  }, [month]);

  const fmtTime = (t) => {
    if (!t) return "-";
    try {
      return new Date(`1970-01-01T${String(t).slice(0, 5)}:00`).toLocaleTimeString("en-MY", {
        hour: "numeric",
        minute: "2-digit",
      });
    } catch {
      return t || "-";
    }
  };

  const durationMinutes = Number(service?.duration_minutes || 60);

  const fmtHm = (t) => {
    if (!t) return "-";
    return String(t).slice(0, 5);
  };

  const endTimeFor = (startTime) => {
    if (!startTime) return null;
    try {
      const dt = new Date(`1970-01-01T${String(startTime).slice(0, 5)}:00`);
      dt.setMinutes(dt.getMinutes() + durationMinutes);
      return dt.toTimeString().slice(0, 5);
    } catch {
      return null;
    }
  };

  const periodOf = (slot) => {
    const h = Number(String(slot.start_time).slice(0, 2));
    if (h < 12) return "morning";
    if (h < 17) return "afternoon";
    return "evening";
  };

  const groupedSlots = useMemo(() => {
    const g = { morning: [], afternoon: [], evening: [] };
    for (const s of slots) g[periodOf(s)].push(s);
    return g;
  }, [slots]);

  /* ─── Reset calendar state when stepping to a new item ─── */
  useEffect(() => {
    setSelectedDate(null);
    setSlots([]);
    setSelectedSlotId(null);
    setSelectedStartTime(null);
    setAvailableDates([]);
    // Reset month to current
    const d = new Date();
    setMonth(new Date(d.getFullYear(), d.getMonth(), 1));
  }, [stepIdx]);

  /* ─── Load month availability ─── */
  useEffect(() => {
    if (!service?.id) return;

    setLoadingDates(true);
    setAvailableDates([]);
    setSelectedDate(null);
    setSlots([]);
    setSelectedSlotId(null);
    setSelectedStartTime(null);

    axios
      .get(route("booking.slots.month"), {
        params: { service_id: service.id, month: monthISO },
      })
      .then((res) => {
        const normalizedDates = (res.data?.dates || []).map((d) => String(d).slice(0, 10));
        setAvailableDates(normalizedDates);
      })
      .catch((err) => {
        setPopup({
          open: true,
          title: "Calendar error",
          message: err?.response?.data?.message || "Failed to load available dates. Please try again.",
        });
      })
      .finally(() => setLoadingDates(false));
  }, [service?.id, monthISO]);

  const changeMonth = (delta) => {
    setMonth((prev) => new Date(prev.getFullYear(), prev.getMonth() + delta, 1));
  };

  const isEnabledDate = (iso) => availableDates.includes(iso);

  const selectDate = (iso) => {
    if (!isEnabledDate(iso)) return;

    setSelectedDate(iso);
    setSelectedSlotId(null);
    setSelectedStartTime(null);
    setSlots([]);

    setLoadingSlots(true);
    axios
      .post(route("booking.slots"), { service_id: service.id, date: iso })
      .then((res) => {
        const list = res.data?.slots || [];
        setSlots(list);
        if (list.length === 0) {
          setPopup({
            open: true,
            title: "No times available",
            message: "No available times for that date. Please choose another date.",
          });
        }
      })
      .catch((err) => {
        setPopup({
          open: true,
          title: "Time slots error",
          message: err?.response?.data?.message || "Failed to load time slots. Please try again.",
        });
      })
      .finally(() => setLoadingSlots(false));
  };

  const isSlotFull = (slot) => {
    if (slot.status === "full") return true;
    if (slot.capacity != null && item?.pax != null && slot.capacity < item.pax) return true;
    return false;
  };

  const selectSlot = (slot) => {
    if (isSlotFull(slot)) return;
    setSelectedSlotId(slot.slot_id);
    setSelectedStartTime(slot.start_time);
  };

  const canNext = Boolean(selectedSlotId);

  /* ─── Save pick and advance to next item, or submit all ─── */
  const next = () => {
    if (!canNext) {
      setPopup({ open: true, title: "Select a time first", message: "Please choose a time slot to continue." });
      return;
    }

    const startTime = selectedStartTime || parseTmpSlotId(selectedSlotId)?.start_time || "";
    const endTime = endTimeFor(startTime);

    const newPicks = {
      ...picks,
      [item.index]: {
        slot_id: selectedSlotId,
        slot_date: selectedDate,
        start_time: startTime,
        end_time: endTime,
      },
    };
    setPicks(newPicks);

    if (stepIdx + 1 < totalSteps) {
      // Move to next cart item
      setStepIdx(stepIdx + 1);
    } else {
      // All items scheduled — submit all picks
      const allPicks = items.map((it) => {
        const p = newPicks[it.index];
        return {
          index: it.index,
          slot_id: p?.slot_id || "",
          slot_date: p?.slot_date || "",
          start_time: p?.start_time || "",
          end_time: p?.end_time || "",
        };
      });

      router.post(route("booking.schedule.confirm"), { picks: allPicks });
    }
  };

  const goBack = () => {
    if (stepIdx > 0) {
      setStepIdx(stepIdx - 1);
    }
  };

  /* ─── Empty cart ─── */
  if (!items.length || !item || !service) {
    return (
      <CustomerLayout title="Choose Date & Time" active="booking">
        <div className="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:py-12">
          <h1 className="text-2xl font-bold">Choose Your Date & Time</h1>
          <p className="text-gray-600 mt-2">Your cart is empty. Please add a service first.</p>
          <Link href={route("booking.services")} className="underline mt-4 inline-block">
            Back to Services
          </Link>
        </div>
      </CustomerLayout>
    );
  }

  const displayStartTime = selectedStartTime || parseTmpSlotId(selectedSlotId)?.start_time || null;
  const displayEndTime = displayStartTime ? endTimeFor(displayStartTime) : null;

  return (
    <CustomerLayout title="Choose Date & Time" active="booking">
      <div className="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:py-10">
        <Popup
          open={popup.open}
          title={popup.title}
          message={popup.message}
          onClose={() => setPopup({ open: false, title: "", message: "" })}
        />

        {/* HEADER */}
        <div className="mb-6 rounded-3xl border border-slate-200 bg-white/90 p-5 shadow-xl backdrop-blur sm:p-7">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <h1 className="text-2xl font-black text-slate-900 sm:text-3xl">Choose Your Date & Time</h1>
              <p className="text-sm font-semibold text-slate-600 sm:text-base">
                Select a date and available time slot for each service in your cart.
              </p>
            </div>
            <Link
              href={route("booking.cart")}
              className="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-extrabold text-slate-700 hover:bg-slate-100"
            >
              Back to Cart
            </Link>
          </div>

          {/* STEPPER INDICATOR */}
          {totalSteps > 1 && (
            <div className="mt-4 flex items-center gap-2">
              {items.map((it, i) => (
                <div
                  key={it.index}
                  className={`flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold ${
                    i === stepIdx
                      ? "bg-pink-500 text-white"
                      : picks[it.index]
                      ? "bg-emerald-100 text-emerald-700"
                      : "bg-slate-100 text-slate-500"
                  }`}
                >
                  <span>{i + 1}.</span>
                  <span className="max-w-[120px] truncate">{it.service?.name || "Service"}</span>
                  {picks[it.index] && <span>&#10003;</span>}
                </div>
              ))}
            </div>
          )}
        </div>

        {/* SERVICE CARD */}
        <div className="mb-6 flex flex-col gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:flex-row">
          <img
            src={service.image_url || "https://placehold.co/240x160/5B21B6/ffffff?text=Service"}
            alt={service.name}
            className="h-24 w-full rounded-xl border border-slate-100 object-cover sm:w-36"
          />
          <div className="flex-1">
            <div className="flex items-center gap-2">
              {totalSteps > 1 && (
                <span className="rounded-full bg-pink-100 px-2 py-0.5 text-xs font-bold text-pink-600">
                  Item {stepIdx + 1} of {totalSteps}
                </span>
              )}
            </div>
            <div className="text-xl font-black text-slate-900">{service.name}</div>
            <div className="mt-1 text-sm font-semibold text-slate-600">
              {service.duration_minutes} mins &bull; RM {Number(service.price).toFixed(2)} / person &bull; People: {item.pax}
            </div>
          </div>
        </div>

        {/* CALENDAR */}
        <div className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6">
          <div className="flex items-center justify-between mb-4">
            <button
              onClick={() => changeMonth(-1)}
              className="h-10 w-10 rounded-full border border-slate-300 hover:bg-slate-100"
              aria-label="Previous month"
            >
              &#8592;
            </button>
            <div className="text-center">
              <div className="font-semibold">{monthLabel}</div>
              <div className="text-xs text-gray-500">GMT+08:00 (Malaysia)</div>
            </div>
            <button
              onClick={() => changeMonth(1)}
              className="h-10 w-10 rounded-full border border-slate-300 hover:bg-slate-100"
              aria-label="Next month"
            >
              &#8594;
            </button>
          </div>

          {/* Weekday header */}
          <div className="mb-3 grid grid-cols-7 gap-2 text-xs font-bold uppercase tracking-wide text-slate-500 sm:gap-3 sm:text-sm">
            {weekday.map((w) => (
              <div key={w} className="text-center">{w}</div>
            ))}
          </div>

          {/* Days grid */}
          <div className="grid grid-cols-7 gap-2 sm:gap-3">
            {daysGrid.map((iso, idx) => {
              if (!iso) return <div key={`empty-${idx}`} />;

              const enabled = isEnabledDate(iso);
              const active = selectedDate === iso;
              const dayNum = Number(iso.slice(8, 10));

              return (
                <button
                  key={iso}
                  disabled={!enabled || loadingDates}
                  onClick={() => selectDate(iso)}
                  className={[
                    "mx-auto h-9 w-9 rounded-full border text-xs font-bold sm:h-12 sm:w-12 sm:text-sm",
                    enabled ? "hover:border-pink-500" : "opacity-30 cursor-not-allowed",
                    active ? "bg-pink-500 text-white border-pink-500" : "",
                  ].join(" ")}
                  title={enabled ? "Available" : "Not available"}
                >
                  {String(dayNum).padStart(2, "0")}
                </button>
              );
            })}
          </div>

          {/* TIMES */}
          <div className="mt-8 border-t border-slate-200 pt-6">
            {!selectedDate ? (
              <p className="text-gray-600 font-semibold">Pick a date to view available times.</p>
            ) : loadingSlots ? (
              <p className="text-gray-600">Loading times...</p>
            ) : (
              <>
                {["morning", "afternoon", "evening"].map((p) => (
                  <div key={p} className="mb-6">
                    <div className="font-semibold capitalize mb-3">{p}</div>
                    {groupedSlots[p].length === 0 ? (
                      <div className="text-gray-400 text-sm">&mdash;</div>
                    ) : (
                      <div className="grid grid-cols-2 gap-2 sm:gap-3 md:grid-cols-4">
                        {groupedSlots[p].map((s) => {
                          const full = isSlotFull(s);
                          const selected = selectedSlotId === s.slot_id;
                          return (
                            <button
                              key={s.slot_id}
                              onClick={() => selectSlot(s)}
                              disabled={full}
                              className={[
                                "rounded-xl border px-3 py-2 text-sm font-extrabold transition",
                                full
                                  ? "cursor-not-allowed border-slate-200 bg-slate-100 text-slate-400"
                                  : selected
                                  ? "bg-pink-500 text-white border-pink-500"
                                  : "hover:border-pink-500",
                              ].join(" ")}
                            >
                              <div>{fmtTime(s.start_time)}</div>
                              {full ? (
                                <div className="mt-1 text-[11px] font-semibold text-slate-400">Fully Booked</div>
                              ) : s.capacity != null ? (
                                <div className="mt-1 text-[11px] font-semibold text-slate-500">
                                  {s.capacity > 1 ? `${s.capacity} slots left` : `${s.capacity} slot left`}
                                </div>
                              ) : null}
                            </button>
                          );
                        })}
                      </div>
                    )}
                  </div>
                ))}
              </>
            )}
          </div>

          {/* FOOTER: selection summary + navigation */}
          <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="text-sm font-semibold text-slate-600">
              {selectedSlotId && displayStartTime ? (
                <span>
                  Selected: <b>{prettyPrintDate(selectedDate)}</b> &bull;{" "}
                  <b>{fmtTime(displayStartTime)}</b> - <b>{fmtHm(displayEndTime)}</b>
                </span>
              ) : (
                <span>Select a time for each service to continue.</span>
              )}
            </div>

            <div className="flex gap-2">
              {stepIdx > 0 && (
                <button
                  onClick={goBack}
                  className="rounded-2xl border border-slate-300 bg-white px-6 py-3 text-sm font-extrabold text-slate-700 hover:bg-slate-100 sm:text-base"
                >
                  Back
                </button>
              )}
              <button
                onClick={next}
                disabled={!canNext}
                className={[
                  "rounded-2xl px-8 py-3 text-sm font-extrabold sm:text-base",
                  canNext ? "bg-black text-white" : "bg-gray-200 text-gray-500 cursor-not-allowed",
                ].join(" ")}
              >
                {stepIdx + 1 < totalSteps ? `Next (${stepIdx + 2}/${totalSteps})` : "Confirm Schedule"}
              </button>
            </div>
          </div>
        </div>
      </div>
    </CustomerLayout>
  );
}

function prettyPrintDate(dateStr) {
  if (!dateStr) return "-";
  const d = new Date(dateStr + "T00:00:00");
  return d.toLocaleDateString("en-MY", { day: "2-digit", month: "short", year: "numeric" });
}
