import React, { useMemo, useState } from "react";
import { Link, router } from "@inertiajs/react";
import CustomerLayout from "@/Layouts/CustomerLayout";

const FALLBACK_IMG = "https://placehold.co/900x600/5B21B6/ffffff?text=UniSpa+Service";

export default function Cart({ cartItems = [], subtotal: propSubtotal, discountAmount = 0, total: propTotal, isUitmMember = false, appliedPromo = null, promoError = null, promoSuccess = null }) {
  const items = Array.isArray(cartItems) ? cartItems : [];
  const [promoCode, setPromoCode] = useState("");

  const totals = useMemo(() => {
    const computedSubtotal = items.reduce((sum, it) => {
      const price = Number(it?.service?.price || 0);
      const qty = Number(it?.quantity || 1);
      return sum + price * qty;
    }, 0);
    const sub = typeof propSubtotal === "number" ? propSubtotal : computedSubtotal;
    const disc = Number(discountAmount) || 0;
    const tot = typeof propTotal === "number" ? propTotal : (sub - disc);
    return { subtotal: sub, discountAmount: disc, total: tot, isUitmMember: Boolean(isUitmMember) };
  }, [items, propSubtotal, discountAmount, propTotal, isUitmMember]);

  const removeItem = (index) => {
    router.post(route("booking.cart.remove"), { index }, { preserveScroll: true });
  };

  const clearCart = () => {
    router.post(route("booking.cart.clear"), {}, { preserveScroll: true });
  };

  return (
    <CustomerLayout title="Booking Cart" active="booking" className="bg-slate-50">
      <div className="mx-auto max-w-4xl px-4 py-10">
        <div className="flex items-end justify-between gap-3">
          <div>
            <h1 className="text-3xl font-extrabold text-slate-900">Your Booking Cart</h1>
            <p className="mt-1 text-slate-600 font-semibold">
              Review your selected services. You’ll pick date & time next.
            </p>
          </div>

          <div className="flex gap-2">
            <Link
              href="/booking/services"
              className="rounded-lg border border-slate-300 bg-white px-4 py-2 font-bold text-slate-800 hover:bg-slate-100"
            >
              Add More
            </Link>
            <button
              onClick={clearCart}
              className="rounded-lg border border-rose-300 bg-white px-4 py-2 font-bold text-rose-700 hover:bg-rose-50"
              type="button"
              disabled={!items.length}
            >
              Clear
            </button>
          </div>
        </div>

        {!items.length ? (
          <div className="mt-8 rounded-2xl border border-slate-200 bg-white p-10 text-center shadow-sm">
            <div className="text-4xl text-slate-300"><i className="fas fa-shopping-cart" /></div>
            <p className="mt-3 text-slate-700 font-extrabold">Your cart is empty.</p>
            <Link
              href="/booking/services"
              className="mt-5 inline-flex items-center gap-2 rounded-xl bg-slate-900 px-5 py-3 font-extrabold text-white hover:opacity-95"
            >
              <i className="fas fa-spa" /> Browse Services
            </Link>
          </div>
        ) : (
          <div className="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-[1fr_340px]">
            {/* Items */}
            <div className="space-y-4">
              {items.map((it) => {
                const s = it.service;
                const qty = Number(it.quantity || 1);
                const price = Number(s?.price || 0);
                const lineTotal = price * qty;

                return (
                  <div key={it.index} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div className="flex gap-4">
                      <img
                        src={s?.image_url || FALLBACK_IMG}
                        alt={s?.name || "Service"}
                        className="h-24 w-28 rounded-xl object-cover border border-slate-100"
                        onError={(e) => (e.currentTarget.src = FALLBACK_IMG)}
                      />

                      <div className="min-w-0 flex-1">
                        <div className="flex items-start justify-between gap-3">
                          <div className="min-w-0">
                            <div className="truncate text-lg font-extrabold text-slate-900">
                              {s?.name || "Unknown Service"}
                            </div>
                            <div className="mt-1 text-sm font-semibold text-slate-600">
                              {s?.duration_minutes ? (
                                <span><i className="fas fa-clock" /> {s.duration_minutes} mins</span>
                              ) : null}
                              <span className="mx-2 text-slate-300">•</span>
                              <span>RM {price.toFixed(2)} / person</span>
                            </div>
                            <div className="mt-2 text-sm font-semibold text-slate-600">
                              People: <span className="font-extrabold text-slate-900">{qty}</span>
                            </div>
                          </div>

                          <div className="text-right">
                            <div className="text-lg font-extrabold text-slate-900">RM {lineTotal.toFixed(2)}</div>
                            <button
                              type="button"
                              onClick={() => removeItem(it.index)}
                              className="mt-2 inline-flex items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-extrabold text-rose-700 hover:bg-rose-100"
                            >
                              <i className="fas fa-trash" /> Remove
                            </button>
                          </div>
                        </div>

                        {/* Participants preview (optional) */}
                        {Array.isArray(it.participants) && it.participants.length ? (
                          <div className="mt-3 rounded-xl bg-slate-50 p-3 text-sm">
                            <div className="font-extrabold text-slate-800 mb-1">Participants</div>
                            <ul className="space-y-1 text-slate-700 font-semibold">
                              {it.participants.slice(0, 3).map((p, idx) => (
                                <li key={idx}>
                                  {p.is_self ? "You" : `Guest ${idx}`} — {p.name || "-"} ({p.phone || "-"})
                                </li>
                              ))}
                            </ul>
                          </div>
                        ) : null}
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>

            {/* Summary */}
            <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm h-fit">
              <div className="text-lg font-extrabold text-slate-900">Summary</div>

              <div className="mt-4 space-y-2 text-sm font-bold text-slate-700">
                <div className="flex justify-between">
                  <span>Subtotal</span>
                  <span>RM {totals.subtotal.toFixed(2)}</span>
                </div>
                {totals.discountAmount > 0 && (
                  <div className="flex justify-between text-emerald-700">
                    <span>UiTM member discount (10%)</span>
                    <span>- RM {totals.discountAmount.toFixed(2)}</span>
                  </div>
                )}
                {appliedPromo && (
                  <div className="flex justify-between text-violet-700">
                    <span>Promo: {appliedPromo.code}</span>
                    <span>- RM {Number(appliedPromo.promo_discount_amount || 0).toFixed(2)}</span>
                  </div>
                )}
                <div className="flex justify-between text-base font-extrabold text-slate-900 border-t border-slate-200 pt-2">
                  <span>Total</span>
                  <span>RM {totals.total.toFixed(2)}</span>
                </div>
              </div>

              {/* Promo code */}
              <div className="mt-4">
                <p className="text-xs font-bold text-slate-500 mb-1">Promo Code</p>
                {promoSuccess && (
                  <p className="mb-2 rounded-lg bg-emerald-50 border border-emerald-200 px-3 py-2 text-xs font-bold text-emerald-700">{promoSuccess}</p>
                )}
                {promoError && (
                  <p className="mb-2 rounded-lg bg-rose-50 border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700">{promoError}</p>
                )}
                {appliedPromo ? (
                  <div className="flex items-center justify-between rounded-lg border border-violet-200 bg-violet-50 px-3 py-2">
                    <span className="text-xs font-extrabold text-violet-700">{appliedPromo.code} applied</span>
                    <button
                      type="button"
                      onClick={() => router.post(route("booking.cart.promo.remove"), {}, { preserveScroll: true })}
                      className="text-xs font-bold text-rose-600 hover:underline"
                    >
                      Remove
                    </button>
                  </div>
                ) : (
                  <div className="flex gap-2">
                    <input
                      type="text"
                      value={promoCode}
                      onChange={(e) => setPromoCode(e.target.value.toUpperCase())}
                      placeholder="Enter promo code"
                      className="flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm"
                      onKeyDown={(e) => {
                        if (e.key === "Enter") {
                          e.preventDefault();
                          router.post(route("booking.cart.promo.apply"), { code: promoCode }, { preserveScroll: true });
                        }
                      }}
                    />
                    <button
                      type="button"
                      onClick={() => router.post(route("booking.cart.promo.apply"), { code: promoCode }, { preserveScroll: true })}
                      className="rounded-lg bg-violet-600 px-3 py-2 text-xs font-extrabold text-white hover:bg-violet-700"
                    >
                      Apply
                    </button>
                  </div>
                )}
              </div>

              <div className="mt-4 h-px bg-slate-200" />

              <div className="mt-4 flex flex-col gap-3">
                <Link
                  href="/booking/services"
                  className="rounded-xl border border-slate-300 bg-white px-5 py-3 text-center font-extrabold text-slate-900 hover:bg-slate-100"
                >
                  Add More
                </Link>

                {/* ✅ Next step (you will implement schedule page next) */}
                <Link
                  href="/booking/schedule"
                  className="rounded-xl bg-slate-900 px-5 py-3 text-center font-extrabold text-white hover:opacity-95"
                >
                  Continue to Date & Time <i className="fas fa-arrow-right ml-2" />
                </Link>
              </div>

              <p className="mt-4 text-xs font-semibold text-slate-500">
                Next: choose therapist, date & time slot.
              </p>
            </div>
          </div>
        )}
      </div>
    </CustomerLayout>
  );
}
