import type { ReactNode } from 'react';
import { Card, CardIconBox, CardSubtitle, CardTitle } from '@/components/Card';

type Props = {
  icon: ReactNode;
  title: ReactNode;
  subtitle: ReactNode;
  selected?: boolean;
  onClick?: () => void;
  right?: ReactNode;
  iconVariant?: 'primary' | 'gold' | 'muted';
  editSlot?: ReactNode;
};

export function ItemCard({ icon, title, subtitle, selected, onClick, right, iconVariant, editSlot }: Props) {
  return (
    <Card hover selected={selected} onClick={onClick} className="flex flex-col gap-6 group relative">
      {right && <div className="absolute top-6 right-6">{right}</div>}
      <CardIconBox variant={iconVariant ?? 'primary'}>{icon}</CardIconBox>
      <div>
        {editSlot ?? <CardTitle>{title}</CardTitle>}
        {!editSlot && <CardSubtitle>{subtitle}</CardSubtitle>}
      </div>
    </Card>
  );
}
