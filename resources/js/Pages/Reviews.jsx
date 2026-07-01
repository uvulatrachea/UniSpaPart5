import React, { useEffect, useMemo, useState } from "react";
import { useForm, usePage } from "@inertiajs/react";
import CustomerLayout from "@/Layouts/CustomerLayout";

export default function Reviews({
  reviews = [],
  topReview = null,
  stats = {},
  canReviewBookings = [],
  initialBookingId = "",
}) {
  const { flash = {} } = usePage().props;
  const [selectedRating, setSelectedRating] = useState(5);
  const [toast, setToast] = useState({ open: false, type: "success", text: "" });

  const resolvedInitial = useMemo(() => {
    if (initialBookingId && canReviewBookings.some((b) => b.booking_id === initialBookingId)) {
      return initialBookingId;
    }
    return canReviewBookings?.[0]?.booking_id || "";
  }, [initialBookingId, canReviewBookings]);

  const { data, setData, post, processing, errors, reset } = useForm({
    booking_id: resolvedInitial,
    rating: 5,
    comment: "",
  });

  useEffect(() => {
    if (flash?.success) {
      setToast({ open: true, type: "success", text: flash.success });
      const t = setTimeout(() => setToast((p) => ({ ...p, open: false })), 5500);
      return () => clearTimeout(t);
    }
    if (flash?.error) {
      setToast({ open: true, type: "error", text: flash.error });
      const t = setTimeout(() => setToast((p) => ({ ...p, open: false })), 5500);
      return () => clearTimeout(t);
    }
  }, [flash?.success, flash?.error]);

  const average = Number(stats?.avgRating ?? 0).toFixed(1);

  const topReviewCard = useMemo(() => {
    if (!topReview) return null;
    return {
      ...topReview,
      customer_name: topReview.customer_name || "Anonymous",
      service_name: topReview.service_name || "Service",
    };
  }, [topReview]);

  const handleSubmit = (e) => {
    e.preventDefault();
    post("/reviews", {
      preserveScroll: true,
      onSuccess: () => {
        reset("comment");
        setSelectedRating(5);
        setData("rating", 5);
      },
    });
  };

  const handleRatingPick = (v) => {
    setSelectedRating(v);
    setData("rating", v);
  };

  return (
    <CustomerLayout title="Customer Reviews" active="reviews">

      {/* Toast */}
      <div
        className={`fixed right-4 top-24 z-50 w-[calc(100%-2rem)] max-w-md transform rounded-2xl border px-4 py-4 shadow-2xl transition-all duration-500 ${
          toast.type === "error"
            ? "border-rose-200 bg-rose-50 text-rose-800"
            : "border-emerald-200 bg-emerald-50 text-emerald-800"
        } ${toast.open ? "translate-x-0 opacity-100" : "pointer-events-none translate-x-12 opacity-0"}`}
        role="status"
        aria-live="polite"
      >
        <div className="flex items-start gap-3">
          <div className="pt-0.5 text-lg">{toast.type === "error" ? "⚠️" : "✅"}</div>
          <p className="flex-1 text-sm font-bold leading-relaxed">{toast.text}</p>
          <button
            onClick={() => setToast((p) => ({ ...p, open: false }))}
            className="rounded-lg px-2 py-1 text-xs font-black hover:bg-black/5"
            aria-label="Close"
          >
            ✕
          </button>
        </div>
      </div>

      <main className="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8 py-8 sm:py-10">
        <section className="rounded-2xl bg-white shadow-xl border border-slate-100 p-6 sm:p-8">
          <h1 className="text-2xl sm:text-3xl font-extrabold text-unispa-primaryDark">Customer Reviews</h1>
          <p className="mt-1 text-slate-600 font-semibold">Read all feedback, top review, and share your own experience.</p>

          <div className="mt-5 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <Stat title="Total Reviews" value={stats?.totalReviews ?? 0} />
            <Stat title="Average Rating" value={average} />
            <Stat title="5-Star Reviews" value={stats?.fiveStarReviews ?? 0} />
            <Stat title="My Reviews" value={stats?.myReviews ?? 0} />
          </div>
        </section>

        {topReviewCard ? (
          <section className="mt-6 rounded-2xl bg-white shadow-xl border border-amber-100 p-6 sm:p-8">
            <div className="flex items-center gap-2 text-amber-700 font-extrabold uppercase tracking-wide text-sm">
              <i className="fas fa-crown" /> Top Review
            </div>
            <div className="mt-2 text-xl font-extrabold text-slate-900">{topReviewCard.service_name}</div>
            <div className="mt-1 text-sm font-semibold text-slate-500">by {topReviewCard.customer_name}</div>
            <div className="mt-3 text-amber-500 text-lg">{"★".repeat(Number(topReviewCard.rating || 0))}</div>
            <p className="mt-3 text-slate-700 font-medium">{topReviewCard.comment || "No comment provided."}</p>
          </section>
        ) : null}

        <section className="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div className="rounded-2xl bg-white shadow-xl border border-slate-100 p-6">
            <h2 className="text-xl font-extrabold text-slate-900">All Customer Reviews</h2>
            <div className="mt-4 space-y-3 max-h-[560px] overflow-auto pr-1">
              {reviews.length ? (
                reviews.map((r) => (
                  <div key={r.review_id} className="rounded-xl border border-slate-100 bg-slate-50 p-4">
                    <div className="flex items-center justify-between gap-3">
                      <p className="font-extrabold text-slate-800">{r.customer_name || "Anonymous"}</p>
                      <span className="text-xs font-bold text-slate-500">{formatDate(r.created_at)}</span>
                    </div>
                    <p className="text-sm font-semibold text-slate-500">{r.service_name || "Service"}</p>
                    <div className="mt-2 text-amber-500 text-sm">{"★".repeat(Number(r.rating || 0))}</div>
                    <p className="mt-2 text-sm text-slate-700">{r.comment || "No comment provided."}</p>
                  </div>
                ))
              ) : (
                <p className="text-sm text-slate-500">No reviews yet.</p>
              )}
            </div>
          </div>

          <div className="rounded-2xl bg-white shadow-xl border border-slate-100 p-6">
            <h2 className="text-xl font-extrabold text-slate-900">Add Your Review</h2>
            <p className="mt-1 text-sm text-slate-500 font-semibold">You can review completed bookings that have not been reviewed yet.</p>

            <form className="mt-4 space-y-4" onSubmit={handleSubmit}>
              <div>
                <label className="text-sm font-bold text-slate-700">Select Booking</label>
                <select
                  value={data.booking_id}
                  onChange={(e) => setData("booking_id", e.target.value)}
                  className="ui-select mt-1"
                >
                  <option value="">Select booking</option>
                  {canReviewBookings.map((b) => (
                    <option key={b.booking_id} value={b.booking_id}>
                      {b.service_name || "Service"} – {formatSlotDate(b.slot_date)}
                    </option>
                  ))}
                </select>
                {errors.booking_id ? <p className="mt-1 text-xs text-rose-600 font-bold">{errors.booking_id}</p> : null}
              </div>

              <div>
                <label className="text-sm font-bold text-slate-700">Rating</label>
                <div className="mt-1 flex gap-2">
                  {[1, 2, 3, 4, 5].map((v) => (
                    <button
                      key={v}
                      type="button"
                      onClick={() => handleRatingPick(v)}
                      className={`h-10 w-10 rounded-lg border text-lg ${selectedRating >= v ? "bg-amber-100 border-amber-300 text-amber-500" : "bg-white border-slate-300 text-slate-400"}`}
                    >
                      ★
                    </button>
                  ))}
                </div>
                {errors.rating ? <p className="mt-1 text-xs text-rose-600 font-bold">{errors.rating}</p> : null}
              </div>

              <div>
                <label className="text-sm font-bold text-slate-700">Comment</label>
                <textarea
                  value={data.comment}
                  onChange={(e) => setData("comment", e.target.value)}
                  rows={5}
                  placeholder="Share your experience..."
                  className="ui-textarea mt-1"
                />
                {errors.comment ? <p className="mt-1 text-xs text-rose-600 font-bold">{errors.comment}</p> : null}
              </div>

              <button
                type="submit"
                disabled={processing || !canReviewBookings.length}
                className="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-unispa-primaryDark to-unispa-primary px-5 py-3 text-white font-extrabold disabled:opacity-60 disabled:cursor-not-allowed"
              >
                <i className="fas fa-paper-plane" /> Submit Review
              </button>

              {!canReviewBookings.length ? (
                <p className="text-xs font-bold text-slate-500">No eligible bookings available for review yet.</p>
              ) : null}
            </form>
          </div>
        </section>
      </main>
    </CustomerLayout>
  );
}

function Stat({ title, value }) {
  return (
    <div className="rounded-xl border border-slate-100 bg-slate-50 p-4">
      <p className="text-xs uppercase tracking-wide font-bold text-slate-500">{title}</p>
      <p className="mt-1 text-2xl font-extrabold text-slate-900">{value}</p>
    </div>
  );
}

function formatDate(value) {
  if (!value) return "-";
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString("en-MY", { day: "2-digit", month: "short", year: "numeric" });
}

function formatSlotDate(value) {
  if (!value) return "Unknown date";
  const d = new Date(value + "T00:00:00");
  if (Number.isNaN(d.getTime())) return value;
  return d.toLocaleDateString("en-MY", { day: "numeric", month: "long", year: "numeric" });
}
