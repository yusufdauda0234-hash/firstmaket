import { Play } from 'lucide-react';
import { useState } from 'react';

export interface ProductVideo {
    provider: string;
    providerName: string;
    embedUrl: string;
    watchUrl: string;
    thumbnailUrl: string | null;
}

/**
 * A video that costs nothing until somebody wants to watch it.
 *
 * An always-mounted YouTube iframe pulls in roughly a megabyte of third-party
 * player code on every product view, whether or not anyone presses play — on a
 * Nigerian mobile connection that is the slowest thing on the page, paid for
 * by every shopper to benefit the few who watch. So until the first click this
 * is a single still image, and the real player is only mounted afterwards,
 * with autoplay so the click that paid for it also starts it.
 */
export default function VideoPlayer({
    video,
    productName,
}: {
    video: ProductVideo;
    productName: string;
}) {
    const [playing, setPlaying] = useState(false);

    if (playing) {
        return (
            <div className="aspect-video overflow-hidden rounded-xl bg-black ring-1 ring-black/5">
                <iframe
                    // autoplay is only ever added after a real click, so this
                    // cannot start on its own.
                    src={`${video.embedUrl}?autoplay=1&rel=0`}
                    title={`${productName} — ${video.providerName}`}
                    className="h-full w-full"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowFullScreen
                    // The player is a third party; keep it out of this origin.
                    sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"
                    referrerPolicy="strict-origin-when-cross-origin"
                />
            </div>
        );
    }

    return (
        <button
            type="button"
            onClick={() => setPlaying(true)}
            aria-label={`Play the ${productName} video on ${video.providerName}`}
            className="group relative block aspect-video w-full overflow-hidden rounded-xl bg-gray-900 ring-1 ring-black/5"
        >
            {video.thumbnailUrl && (
                <img
                    src={video.thumbnailUrl}
                    alt=""
                    loading="lazy"
                    decoding="async"
                    className="h-full w-full object-cover opacity-90 transition group-hover:opacity-100"
                />
            )}
            <span className="absolute inset-0 flex items-center justify-center">
                <span className="flex h-14 w-14 items-center justify-center rounded-full bg-black/60 text-white shadow-lg backdrop-blur-sm transition group-hover:scale-110 group-hover:bg-brand-600">
                    <Play className="ml-0.5 h-6 w-6 fill-current" />
                </span>
            </span>
            <span className="absolute bottom-2 right-2 rounded bg-black/60 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                {video.providerName}
            </span>
        </button>
    );
}
