import { common } from './common';
import { nav } from './nav';
import { errors } from './errors';
import { auth } from './auth';
import { home } from './home';
import { daily } from './daily';
import { game } from './game';
import { packs } from './packs';
import { tournaments } from './tournaments';
import { friends } from './friends';
import { profile } from './profile';
import { notifications } from './notifications';
import { clubs } from './clubs';

/**
 * English — the source of truth. Every other locale is typed as
 * `Translations`, so a missing key fails `tsc --noEmit`.
 */
export const en = {
  common,
  nav,
  errors,
  auth,
  home,
  daily,
  game,
  packs,
  tournaments,
  friends,
  profile,
  notifications,
  clubs,
};

export type Translations = typeof en;
